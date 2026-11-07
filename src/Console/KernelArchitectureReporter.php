<?php

declare(strict_types=1);

namespace Sillbeam\Core\Console;

use Sillbeam\Core\Api\CoreDataConfiguration;
use Sillbeam\Core\Architecture\ArchitectureAnalyzer;
use Sillbeam\Core\Architecture\ArchitectureReport;
use Sillbeam\Core\Architecture\DiscoveryOptionsResolver;
use Sillbeam\Core\DependencyInjection\SillbeamCoreExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Prepares the real kernel's configuration, without compiling or booting it.
 *
 * @internal
 */
final class KernelArchitectureReporter
{
    /**
     * Prepares fresh bundle/configuration state and analyzes without compiling or booting the kernel.
     *
     * Initializes bundle hooks and reads application configuration; descriptor code may execute.
     *
     * @throws \LogicException if kernel preparation, bundle registration or configuration is incompatible
     */
    public function report(KernelInterface $kernel): ArchitectureReport
    {
        $input = $this->configuration($kernel);

        return ArchitectureReport::fromAnalysis((new ArchitectureAnalyzer())->analyze($input->options, $input->config), $input->config, $input->projectDir);
    }

    public function configuration(KernelInterface $kernel): CoreDataConfiguration
    {
        $kernel = clone $kernel;
        // Symfony has no public API for preparing only the uncompiled configuration.
        // Keep the two protected-hook calls here and verify them across supported minors.
        $this->initializeFreshBundles($kernel);
        $container = (new \ReflectionMethod($kernel, 'buildContainer'))->invoke($kernel);
        if (!$container instanceof ContainerBuilder || $container->isCompiled()) {
            throw new \LogicException('The kernel must prepare an uncompiled Symfony ContainerBuilder for architecture reporting.');
        }
        $extension = $container->getExtension('sillbeam');
        if (!$extension instanceof SillbeamCoreExtension) {
            throw new \LogicException('SillbeamCoreBundle must be registered in the selected environment.');
        }
        foreach ($container->getExtensions() as $candidate) {
            if (!$container->getExtensionConfig($candidate->getAlias())) {
                $container->loadFromExtension($candidate->getAlias(), []);
            }
        }
        $initial = null;
        $initialOptions = null;
        foreach ($container->getExtensions() as $candidate) {
            if ($candidate === $extension) {
                [$initialConfigs, $initial] = $extension->resolveConfiguration($container->getExtensionConfig('sillbeam'), $container);
                if ($initial['doctrine']['enabled']) {
                    $initialOptions = (new DiscoveryOptionsResolver())->resolve($container, $initialConfigs, $initial);
                }
            } elseif ($candidate instanceof PrependExtensionInterface) {
                $candidate->prepend($container);
            }
        }
        [$configs, $config] = $extension->resolveConfiguration($container->getExtensionConfig('sillbeam'), $container);
        $options = (new DiscoveryOptionsResolver())->resolve($container, $configs, $config);
        if (($initial['doctrine']['enabled'] ?? false) || $config['doctrine']['enabled']) {
            if ($initial !== $config || $initialOptions != $options) {
                throw new \LogicException('Sillbeam configuration changed after prepend() while Doctrine integration is enabled. Register bundles that prepend Sillbeam configuration before SillbeamCoreBundle, or configure Sillbeam directly in the application.');
            }
        }

        return new CoreDataConfiguration($options, $kernel->getProjectDir(), $config);
    }

    private function initializeFreshBundles(KernelInterface $kernel): void
    {
        // Use Symfony's real initialization hook, including application overrides.
        // A temporary build location on this clone prevents Symfony 8.1 from reading
        // an old production bundle list. Restore it before loading configuration.
        $warmupDir = new \ReflectionProperty(\Symfony\Component\HttpKernel\Kernel::class, 'warmupDir');
        $previous = $warmupDir->getValue($kernel);
        $warmupDir->setValue($kernel, $kernel->getBuildDir().'/.sillbeam-report-'.bin2hex(random_bytes(16)));
        try {
            (new \ReflectionMethod($kernel, 'initializeBundles'))->invoke($kernel);
        } finally {
            $warmupDir->setValue($kernel, $previous);
        }
    }
}
