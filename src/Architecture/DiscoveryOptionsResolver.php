<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture;

use Sillbeam\Core\I18n\CoreError;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Resolves Symfony configuration and Composer PSR-4 mappings into build-time discovery options.
 *
 * @internal
 */
final class DiscoveryOptionsResolver
{
    /**
     * Reads Composer metadata and resolves configuration paths relative to kernel.project_dir.
     *
     * @param array<array-key, array<string, mixed>> $configs
     * @param array<string, mixed>|null              $processedConfig
     *
     * @throws \LogicException if paths, namespaces or Composer mappings cannot be resolved unambiguously
     * @throws \JsonException  if Composer metadata is invalid JSON
     */
    public function resolve(ContainerBuilder $container, array $configs, ?array $processedConfig = null): DiscoveryOptions
    {
        $raw = [];
        foreach ($configs as $config) {
            if (isset($config['discovery']) && \is_array($config['discovery'])) {
                foreach ($config['discovery'] as $key => $value) {
                    $raw[$key] = \in_array($key, ['modules', 'projects', 'excludes'], true) ? array_merge($raw[$key] ?? [], $value) : $value;
                }
            }
        }

        $moduleRootsConfigured = \array_key_exists('modules', $raw);
        $projectRootsConfigured = \array_key_exists('projects', $raw);
        $raw = $processedConfig['discovery'] ?? $raw;

        $projectDir = $container->getParameter('kernel.project_dir');
        if (!\is_string($projectDir)) {
            throw CoreError::create(\LogicException::class, 'error.discovery.kernel_dir');
        }
        $projectDir = FilesystemPath::normalize($projectDir);
        $configuredRoot = $raw['root'] ?? $projectDir.'/src';
        if (!\is_string($configuredRoot)) {
            throw CoreError::create(\LogicException::class, 'error.discovery.root');
        }
        $root = $this->resolvePath($configuredRoot, $projectDir);
        $configuredNamespace = $raw['namespace'] ?? null;
        if (null !== $configuredNamespace && !\is_string($configuredNamespace)) {
            throw CoreError::create(\LogicException::class, 'error.discovery.namespace_type');
        }
        $namespace = null !== $configuredNamespace ? trim($configuredNamespace, '\\').'\\' : null;

        $namespace ??= $this->resolveNamespace($projectDir, $root);
        $this->validateNamespace($namespace);
        $integrations = [];
        foreach ($configs as $config) {
            foreach ($config['integrations'] ?? [] as $key => $value) {
                if ('services' === $key) {
                    foreach ($value as $setting => $patterns) {
                        $integrations[$key][$setting] = array_merge($integrations[$key][$setting] ?? [], $patterns);
                    }
                } else {
                    $integrations[$key] = array_merge($integrations[$key] ?? [], $value);
                }
            }
        }

        $integrations = $processedConfig['integrations'] ?? $integrations;

        return new DiscoveryOptions(
            $root, $namespace,
            $this->roots($moduleRootsConfigured ? $raw['modules'] : null, $projectDir),
            $this->roots($projectRootsConfigured ? $raw['projects'] : null, $projectDir),
            $raw['excludes'] ?? [],
            $integrations['controllers'] ?? ['Controller', '**/Controller'],
            $integrations['entities'] ?? ['Entity', '**/Entity'],
            $integrations['services']['exclude'] ?? ['Entity/**', '**/Entity/**', 'Dto/**', '**/Dto/**', 'Tests/**', '**/Tests/**', 'Fixtures/**', '**/Fixtures/**', 'Migrations/**', '**/Migrations/**'],
            $this->applicationRoots($projectDir),
        );
    }

    /** @return list<DiscoveryRoot> */
    private function applicationRoots(string $projectDir): array
    {
        $composerFile = $projectDir.'/composer.json';
        if (!is_file($composerFile)) {
            return [];
        }
        $composer = json_decode((string) file_get_contents($composerFile), true, flags: \JSON_THROW_ON_ERROR);
        $vendor = new DiscoveryRoot($this->resolvePath($composer['config']['vendor-dir'] ?? 'vendor', $projectDir), '');
        $roots = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach ($composer[$section]['psr-4'] ?? [] as $namespace => $paths) {
                // A fallback prefix cannot prove that a missing class is application-owned.
                if ('' === $namespace) {
                    continue;
                }
                $namespace = trim($namespace, '\\').'\\';
                $this->validateNamespace($namespace);
                foreach ((array) $paths as $path) {
                    $path = $this->resolvePath($path, $projectDir);
                    if (!$vendor->contains($path)) {
                        $roots[] = new DiscoveryRoot($path, $namespace);
                    }
                }
            }
        }

        return $roots;
    }

    private function resolvePath(string $path, string $projectDir): string
    {
        $path = FilesystemPath::normalize(str_replace('%kernel.project_dir%', $projectDir, $path));
        if (!str_starts_with($path, '/') && !preg_match('/^[a-zA-Z]:\//', $path)) {
            $path = FilesystemPath::directoryPrefix($projectDir).$path;
        }
        $resolved = realpath($path);

        return FilesystemPath::normalize(false === $resolved ? $path : $resolved);
    }

    private function resolveNamespace(string $projectDir, string $root): string
    {
        $composerFile = $projectDir.'/composer.json';
        if (!is_file($composerFile)) {
            throw CoreError::create(\LogicException::class, 'error.discovery.composer');
        }

        $composer = json_decode((string) file_get_contents($composerFile), true, flags: \JSON_THROW_ON_ERROR);
        $mappings = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach (($composer[$section]['psr-4'] ?? []) as $namespace => $paths) {
                foreach ((array) $paths as $path) {
                    $mappingPath = realpath($projectDir.'/'.FilesystemPath::normalize((string) $path));
                    if (false === $mappingPath) {
                        continue;
                    }
                    $mappingPath = FilesystemPath::normalize($mappingPath);
                    if (!(new DiscoveryRoot($mappingPath, (string) $namespace))->contains($root)) {
                        continue;
                    }
                    $mapping = ['namespace' => trim((string) $namespace, '\\').'\\', 'path' => $mappingPath];
                    $mappings[serialize($mapping)] = $mapping;
                }
            }
        }

        $mappings = array_values($mappings);
        usort($mappings, static fn (array $left, array $right): int => \strlen($right['path']) <=> \strlen($left['path']));
        if ([] === $mappings || (isset($mappings[1]) && \strlen($mappings[0]['path']) === \strlen($mappings[1]['path']))) {
            throw CoreError::create(\LogicException::class, 'error.discovery.psr4', ['path' => $root]);
        }

        $suffix = trim(substr($root, \strlen($mappings[0]['path'])), '/');

        return $mappings[0]['namespace'].('' === $suffix ? '' : str_replace('/', '\\', $suffix).'\\');
    }

    /** @param list<array{path: string, namespace?: ?string, excludes?: list<string>}>|null $roots
     * @return list<DiscoveryRoot>|null
     */
    private function roots(?array $roots, string $projectDir): ?array
    {
        if (null === $roots) {
            return null;
        }
        $result = [];
        foreach ($roots as $root) {
            $path = $this->resolvePath($root['path'], $projectDir);
            if (!is_dir($path)) {
                throw CoreError::create(\LogicException::class, 'error.discovery.missing_directory', ['path' => $path]);
            }
            $namespace = isset($root['namespace']) ? trim($root['namespace'], '\\').'\\' : $this->resolveNamespace($projectDir, $path);
            $this->validateNamespace($namespace);
            $result[] = new DiscoveryRoot($path, $namespace, $root['excludes'] ?? []);
        }

        return $result;
    }

    private function validateNamespace(string $namespace): void
    {
        if (!preg_match('/^(?:[a-zA-Z_][a-zA-Z0-9_]*\\\\)+$/D', $namespace)) {
            throw CoreError::create(\LogicException::class, 'error.discovery.invalid_namespace', ['namespace' => $namespace]);
        }
    }
}
