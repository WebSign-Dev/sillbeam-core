<?php

declare(strict_types=1);

namespace Sillbeam\Core\DependencyInjection\Compiler;

use Sillbeam\Core\Module\ModuleRegistryInterface;
use Sillbeam\Core\Project\ProjectRegistryInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Enforces registry definitions and aliases when Core runtime integration is active.
 *
 * @internal
 */
final class ArchitectureValidationPass implements CompilerPassInterface
{
    /**
     * Verifies required definitions unless analysis explicitly deferred integration.
     *
     * @throws \LogicException if registries or aliases are missing
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasParameter('sillbeam.architecture.integration_deferred') && true === $container->getParameter('sillbeam.architecture.integration_deferred')) {
            return;
        }
        if (!$container->hasDefinition('sillbeam.module_registry') || !$container->hasDefinition('sillbeam.project_registry')) {
            throw new \LogicException('Sillbeam architecture registries were not compiled.');
        }
        if (!$container->hasAlias(ModuleRegistryInterface::class) || !$container->hasAlias(ProjectRegistryInterface::class)) {
            throw new \LogicException('Sillbeam architecture registry aliases are missing.');
        }
    }
}
