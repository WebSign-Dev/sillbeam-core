<?php

declare(strict_types=1);

namespace Sillbeam\Core\DependencyInjection;

use Sillbeam\Core\Architecture\ArchitectureAnalysisResult;
use Sillbeam\Core\Architecture\ArchitectureAnalyzer;
use Sillbeam\Core\Architecture\ArchitectureReport;
use Sillbeam\Core\Architecture\AreaDirectoryMap;
use Sillbeam\Core\Architecture\CompiledArchitecture;
use Sillbeam\Core\Architecture\Console\ArchitectureCommand;
use Sillbeam\Core\Architecture\Console\GraphCommand;
use Sillbeam\Core\Architecture\Console\InspectModuleCommand;
use Sillbeam\Core\Architecture\Console\MakeModuleCommand;
use Sillbeam\Core\Architecture\Console\MakeProjectCommand;
use Sillbeam\Core\Architecture\Console\ReportCommand;
use Sillbeam\Core\Architecture\DescriptorCreator;
use Sillbeam\Core\Architecture\DiscoveryOptions;
use Sillbeam\Core\Architecture\DiscoveryOptionsResolver;
use Sillbeam\Core\Architecture\DiscoveryRoot;
use Sillbeam\Core\Architecture\FilesystemPath;
use Sillbeam\Core\Architecture\PathMatcher;
use Sillbeam\Core\Architecture\SourceInspector;
use Sillbeam\Core\Console\Doctor\DoctorCommand;
use Sillbeam\Core\Console\LocaleCommand;
use Sillbeam\Core\I18n\CoreTranslator;
use Sillbeam\Core\I18n\LocaleResolver;
use Sillbeam\Core\I18n\UserLocaleStore;
use Sillbeam\Core\Kernel\Command\AboutCommand;
use Sillbeam\Core\Kernel\Runtime\KernelInformation;
use Sillbeam\Core\Kernel\Runtime\KernelInformationInterface;
use Sillbeam\Core\Module\ModuleRegistry;
use Sillbeam\Core\Module\ModuleRegistryInterface;
use Sillbeam\Core\Project\ProjectContext;
use Sillbeam\Core\Project\ProjectContextInterface;
use Sillbeam\Core\Project\ProjectRegistry;
use Sillbeam\Core\Project\ProjectRegistryInterface;
use Sillbeam\Core\Routing\SillbeamRouteLoader;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Compiles architecture into runtime registries, services, routes and optional Doctrine mappings.
 *
 * Doctrine preparation runs during prepend(); changed configuration after that phase is rejected.
 * Without Doctrine, analysis waits until all bundles have prepended configuration.
 * Warm runtime requests consume compiled data and never instantiate the source analyzer.
 *
 * @internal
 */
final class SillbeamCoreExtension extends Extension implements PrependExtensionInterface
{
    private ?ContainerBuilder $preparationContainer = null;
    private ?ArchitectureAnalysisResult $analysis = null;
    private ?DiscoveryOptions $options = null;
    private ?SourceInspector $inspector = null;
    /** @var array<string, mixed> */
    private array $config = [];
    /** @var list<array{prefix: string, directory: string, excludes: list<string>}> */
    private array $doctrineMappings = [];

    /** @var list<string> */
    private array $configurationResources = [];

    public function getAlias(): string
    {
        return 'sillbeam';
    }

    /**
     * Prepares architecture-dependent Doctrine mappings before Doctrine configuration is loaded.
     *
     * @throws \LogicException                                                         if configuration or architecture preparation fails
     * @throws \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException on cold non-debug HTTP compilation
     */
    public function prepend(ContainerBuilder $container): void
    {
        ArchitectureBuildContext::assertCompilationAllowed($container);
        $this->preparationContainer = $container;
        $this->doctrineMappings = [];
        $this->configurationResources = [];
        foreach ($container->getResources() as $resource) {
            if ($resource instanceof FileResource) {
                $this->configurationResources[] = $resource->getResource();
            }
        }
        $this->analysis = null;
        [$configs, $config] = $this->resolveConfiguration($container->getExtensionConfig($this->getAlias()), $container);
        $this->config = $config;
        if ($config['doctrine']['enabled']) {
            $this->analyze($configs, $config, $container);
            if (null !== $this->analysis?->architecture) {
                $this->prependDoctrine($container, $this->analysis->architecture);
            }
        }
    }

    /**
     * Registers diagnostics and tracked resources, and integrates only a validated architecture.
     *
     * @param array<array-key, array<string, mixed>> $configs
     *
     * @throws \LogicException                                                         if build state is inconsistent or Doctrine configuration changed after prepend()
     * @throws \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException on cold non-debug HTTP compilation
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        ArchitectureBuildContext::assertCompilationAllowed($container);
        $configurationContainer = $this->preparationContainer ?? $container;
        [$configs, $config] = $this->resolveConfiguration($configs, $configurationContainer);
        if (null !== $this->preparationContainer && ($this->config['doctrine']['enabled'] || $config['doctrine']['enabled'])) {
            $options = (new DiscoveryOptionsResolver())->resolve($configurationContainer, $configs, $config);
            if (null === $this->analysis || array_diff_key($config, ['locale' => true]) !== array_diff_key($this->config, ['locale' => true]) || $options != $this->options) {
                // Doctrine may already have loaded the mappings prepared during prepend().
                throw new \LogicException('Sillbeam configuration changed after prepend() while Doctrine integration is enabled. Register bundles that prepend Sillbeam configuration before SillbeamCoreBundle, or configure Sillbeam directly in the application.');
            }
        }
        if (null === $this->analysis) {
            // Without Doctrine, validate only after every bundle has prepended its configuration.
            $this->analyze($configs, $config, $configurationContainer);
        }
        $analysis = $this->analysis ?? throw new \LogicException('Sillbeam architecture was not analyzed.');
        $this->analysis = null;
        $options = $this->options ?? throw new \LogicException('Missing discovery options.');
        $resources = $analysis->discovery->resources ?? array_column($options->roots(), 'path');
        foreach ($resources as $resource) {
            $container->addResource(is_dir($resource) ? new DirectoryResource($resource, '/\\.php$/') : (is_file($resource) ? new FileResource($resource) : new FileExistenceResource($resource)));
        }
        $projectDir = $container->getParameter('kernel.project_dir');
        if (!\is_string($projectDir)) {
            throw new \LogicException('kernel.project_dir must be a string.');
        }
        $projectDir = FilesystemPath::normalize($projectDir);
        $composer = $projectDir.'/composer.json';
        $container->addResource(is_file($composer) ? new FileResource($composer) : new FileExistenceResource($composer));
        // Also detect newly added configuration, including the first sillbeam.yaml.
        $configurationDirectory = $projectDir.'/config';
        $container->addResource(is_dir($configurationDirectory) ? new DirectoryResource($configurationDirectory) : new FileExistenceResource($configurationDirectory));
        $report = ArchitectureReport::fromLocalizedAnalysis($analysis, $config, $projectDir, new CoreTranslator(), compiled: true);
        // Keep the report opaque to compiler passes and preserve literal parameter syntax.
        $container->register(ArchitectureReport::class)
            ->setFactory([CompiledArchitectureReport::class, 'restore'])
            ->setArguments([base64_encode(serialize([$report->data, $report->presentation]))]);
        $architecture = $analysis->architecture;
        $container->register(SillbeamRouteLoader::class)->setArguments([
            new Reference('routing.loader.attribute.file'),
            null === $architecture ? null : new Reference(ModuleRegistryInterface::class),
            null === $architecture ? null : new Reference(ProjectRegistryInterface::class),
            $resources, null === $architecture ? [] : $this->controllerFiles($architecture),
            array_values(array_unique([...$this->configurationResources, $projectDir.'/config', $composer])),
        ])->addTag('routing.loader', ['priority' => 100]);
        $container->register(UserLocaleStore::class)->setFactory([UserLocaleStore::class, 'fromEnvironment']);
        $container->register(LocaleResolver::class)->setFactory([LocaleResolver::class, 'forProject'])
            ->setArguments([new Reference(UserLocaleStore::class), $config['locale']]);
        $container->register(LocaleCommand::class)->setArguments([new Reference(UserLocaleStore::class), new Reference(LocaleResolver::class)])->setAutoconfigured(true);
        $container->register(DoctorCommand::class)->setArguments([$projectDir, '%kernel.environment%', new Reference('kernel')])->addMethodCall('useLocaleResolver', [new Reference(LocaleResolver::class)])->setAutoconfigured(true);
        $container->register(GraphCommand::class)->setArguments([new Reference(ArchitectureReport::class)])->addMethodCall('useLocaleResolver', [new Reference(LocaleResolver::class)])->setAutoconfigured(true);
        $container->register(InspectModuleCommand::class)->setArguments([new Reference(ArchitectureReport::class)])->addMethodCall('useLocaleResolver', [new Reference(LocaleResolver::class)])->setAutoconfigured(true);
        $container->register(ArchitectureCommand::class)->setArguments([new Reference(ArchitectureReport::class)])->addMethodCall('useLocaleResolver', [new Reference(LocaleResolver::class)])->setAutoconfigured(true);
        $container->register(ReportCommand::class)->setArguments([new Reference(ArchitectureReport::class), $projectDir])->addMethodCall('useLocaleResolver', [new Reference(LocaleResolver::class)])->setAutoconfigured(true);
        $rootDefinition = static fn (DiscoveryRoot $root): Definition => new Definition(DiscoveryRoot::class, [$root->path, $root->namespace, $root->excludes]);
        $container->register(DiscoveryOptions::class)->setArguments([
            $options->root, $options->namespace,
            array_map($rootDefinition, $options->moduleRoots), array_map($rootDefinition, $options->projectRoots),
            $options->excludes, $options->controllerPatterns, $options->entityPatterns, $options->serviceExcludes,
            array_map($rootDefinition, $options->applicationRoots),
        ]);
        $container->register(DescriptorCreator::class)->setArguments([new Reference(DiscoveryOptions::class)]);
        foreach ([MakeModuleCommand::class, MakeProjectCommand::class] as $command) {
            $container->register($command)->setArguments([new Reference(DescriptorCreator::class)])->addMethodCall('useLocaleResolver', [new Reference(LocaleResolver::class)])->setAutoconfigured(true);
        }
        if (!$analysis->isValid()) {
            $container->setParameter('sillbeam.architecture.integration_deferred', true);

            return;
        }
        $this->loadRuntime($container, $architecture ?? throw new \LogicException('Sillbeam architecture was not compiled.'), $analysis);
    }

    private function loadRuntime(ContainerBuilder $container, CompiledArchitecture $architecture, ArchitectureAnalysisResult $analysis): void
    {
        $modules = [];
        foreach ($architecture->modules as $class => $module) {
            $data = get_object_vars($module);
            $data['dependencyReferences'] = $analysis->references[$class] ?? [];
            $data['observedDependencies'] = array_values(array_unique(array_column($data['dependencyReferences'], 'module')));
            $data['activationReasons'] = $architecture->activationReasons($class);
            $modules[$class] = $data;
        }
        $projects = array_map(static fn ($project): array => get_object_vars($project), $architecture->projects);
        $container->register('sillbeam.module_registry', ModuleRegistry::class)->setArguments([$modules, $architecture->applicationModules, $architecture->projectModules]);
        $container->setAlias(ModuleRegistryInterface::class, 'sillbeam.module_registry')->setPublic(true);
        $container->register('sillbeam.project_registry', ProjectRegistry::class)->setArguments([$projects]);
        $container->setAlias(ProjectRegistryInterface::class, 'sillbeam.project_registry')->setPublic(true);
        $container->autowire(ProjectContext::class)->setArguments([new Reference('request_stack'), new Reference(ProjectRegistryInterface::class), new Reference(ModuleRegistryInterface::class)]);
        $container->setAlias(ProjectContextInterface::class, ProjectContext::class)->setPublic(true);
        $this->loadServices($container, $architecture);
        $container->autowire(KernelInformation::class)->setArguments([new Reference('kernel'), new Reference(ModuleRegistryInterface::class)]);
        $container->setAlias(KernelInformationInterface::class, KernelInformation::class);
        $container->register(AboutCommand::class)->setArguments([new Reference(KernelInformationInterface::class)])->addMethodCall('useLocaleResolver', [new Reference(LocaleResolver::class)])->setAutoconfigured(true);
        $container->setParameter('sillbeam.doctrine.mappings', $this->doctrineMappings);
    }

    /**
     * @param array<array-key, array<string, mixed>> $configs
     * @param array<string, mixed>                   $config
     */
    private function analyze(array $configs, array $config, ContainerBuilder $container): void
    {
        $this->options = (new DiscoveryOptionsResolver())->resolve($container, $configs, $config);
        $this->inspector = new SourceInspector();
        $this->analysis = (new ArchitectureAnalyzer($this->inspector))->analyze($this->options, $config);
    }

    /**
     * @internal shared with pre-compilation reporting; use the same configuration semantics
     *
     * @param array<array-key, array<string, mixed>> $configs
     *
     * @return array{array<array-key, array<string, mixed>>, array<string, mixed>}
     */
    public function resolveConfiguration(array $configs, ContainerBuilder $container): array
    {
        $configs = $this->resolvePlaceholders($configs, $container);
        $config = $this->processConfiguration(new Configuration(), $configs);
        $config = $container->getParameterBag()->resolveValue($config);
        $hasDoctrine = $container->hasExtension('doctrine');
        $config['doctrine']['enabled'] ??= $hasDoctrine;
        if ($config['doctrine']['enabled'] && !$hasDoctrine) {
            throw new \LogicException('sillbeam.doctrine is enabled, but DoctrineBundle is not registered.');
        }

        return [$configs, $config];
    }

    /** Resolves just the locale using the same Symfony node and placeholder handling. */
    public function resolveLocale(ContainerBuilder $container): string
    {
        $configs = array_map(static fn (array $config): array => array_intersect_key($config, ['locale' => true]), $container->getExtensionConfig($this->getAlias()));

        return $this->processConfiguration(new Configuration(), $this->resolvePlaceholders($configs, $container))['locale'];
    }

    /**
     * @param array<array-key, array<string, mixed>> $configs
     *
     * @return array<array-key, array<string, mixed>>
     */
    private function resolvePlaceholders(array $configs, ContainerBuilder $container): array
    {
        return $container->resolveEnvPlaceholders($container->getParameterBag()->resolveValue($configs), true);
    }

    private function prependDoctrine(ContainerBuilder $container, CompiledArchitecture $architecture): void
    {
        $mappings = [];
        $definitions = array_values($architecture->projects);
        foreach ($architecture->usedModules() as $class) {
            $definitions[] = $architecture->modules[$class];
        }
        foreach ($definitions as $definition) {
            foreach ($definition->entityDirectories as $directory) {
                $relative = trim(substr($directory, \strlen($definition->directory)), '/');
                $prefix = $definition->namespace.('' === $relative ? '' : '\\'.str_replace('/', '\\', $relative));
                $key = 'Sillbeam'.hash('xxh128', $directory);
                $this->doctrineMappings[] = ['prefix' => $prefix, 'directory' => $directory, 'excludes' => $this->excludedMappingFiles($directory, $definition->class, $architecture)];
                $mappings[$key] = ['type' => 'attribute', 'is_bundle' => false, 'dir' => $directory, 'prefix' => $prefix, 'alias' => $key];
            }
        }
        if ([] !== $mappings) {
            $container->prependExtensionConfig('doctrine', ['orm' => ['mappings' => $mappings]]);
        }
    }

    /** @return array<string, list<string>> */
    private function controllerFiles(CompiledArchitecture $architecture): array
    {
        $files = $this->inspector?->files($this->options ?? throw new \LogicException('Missing discovery options.')) ?? [];
        $definitions = $architecture->projects;
        foreach ($architecture->usedModules() as $class) {
            $definitions[$class] = $architecture->modules[$class];
        }
        $result = array_fill_keys(array_keys($definitions), []);
        $modules = new AreaDirectoryMap(array_column($architecture->modules, 'class', 'directory'));
        $projects = new AreaDirectoryMap(array_column($architecture->projects, 'class', 'directory'));
        foreach ($files as $file) {
            $class = $modules->owner($file->path) ?? $projects->owner($file->path);
            if (null === $class || !isset($definitions[$class])) {
                continue;
            }
            foreach ($definitions[$class]->controllerDirectories as $directory) {
                if (str_starts_with($file->path, $directory.'/')) {
                    $result[$class][] = $file->path;
                    break;
                }
            }
        }

        return $result;
    }

    private function belongsToArea(string $path, string $class, CompiledArchitecture $architecture): bool
    {
        $definition = $architecture->modules[$class] ?? $architecture->projects[$class];
        if (!str_starts_with($path, $definition->directory.'/')) {
            return false;
        }
        foreach ($architecture->modules as $module) {
            if ($module->class !== $class && str_starts_with($path, $module->directory.'/')) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function excludedMappingFiles(string $directory, string $class, CompiledArchitecture $architecture): array
    {
        $options = $this->options ?? throw new \LogicException('Missing discovery options.');
        $files = ($this->inspector ?? throw new \LogicException('Missing source index.'))->files($options);
        $excludes = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }
            $path = FilesystemPath::normalize($file->getPathname());
            if (!isset($files[$path]) || !$this->belongsToArea($path, $class, $architecture)) {
                $excludes[] = $path;
            }
        }

        return $excludes;
    }

    private function loadServices(ContainerBuilder $container, CompiledArchitecture $architecture): void
    {
        $options = $this->options ?? throw new \LogicException('Sillbeam discovery options are missing.');
        $files = ($this->inspector ?? throw new \LogicException('Sillbeam source index is missing.'))->files($options);
        $loader = new ArchitectureServiceLoader();
        $modules = [];
        foreach ($architecture->usedModules() as $class) {
            $modules[$class] = $architecture->modules[$class];
        }
        $definitions = [...$architecture->projects, ...$modules];
        $areas = [
            new AreaDirectoryMap(array_column($architecture->projects, null, 'directory')),
            new AreaDirectoryMap(array_column($modules, null, 'directory')),
        ];
        $excludes = [];
        foreach ($areas as $area) {
            foreach ($architecture->modules as $module) {
                foreach ($area->containing($module->directory.'/') as $definition) {
                    if ($module->class !== $definition->class) {
                        $excludes[$definition->class][] = $module->directory.'/';
                    }
                }
            }
            foreach ($files as $file) {
                foreach ($area->containing($file->path) as $definition) {
                    $relative = substr($file->path, \strlen($definition->directory) + 1);
                    if ($options->excluded($file->path) || PathMatcher::matches($relative, $options->serviceExcludes)) {
                        $excludes[$definition->class][] = $file->path;
                    }
                }
            }
            // Propagate pruned paths through every enclosing service import.
            foreach ($this->inspector->excludedPaths($options) as $path) {
                foreach ($area->containing($path) as $definition) {
                    $excludes[$definition->class][] = $path;
                }
            }
        }
        foreach ($definitions as $definition) {
            $loader->load($container, $definition->directory, $definition->namespace, $definition->descriptorFile, false, $excludes[$definition->class] ?? [], true);
        }
        $loader->finish();
    }
}
