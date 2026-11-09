<?php

declare(strict_types=1);

namespace Sillbeam\Core\DependencyInjection;

use Sillbeam\Core\I18n\CoreTranslator;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Defines the sillbeam configuration tree while preserving native Symfony integration defaults.
 *
 * @internal
 */
final class Configuration implements ConfigurationInterface
{
    /** @return TreeBuilder<'array'> */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('sillbeam');
        $children = $tree->getRootNode()->children();
        $children->enumNode('locale')->values(CoreTranslator::locales())->defaultValue(CoreTranslator::DEFAULT_LOCALE)->end();
        $discovery = $children->arrayNode('discovery')->addDefaultsIfNotSet()->children();
        $discovery->scalarNode('root')->defaultValue('%kernel.project_dir%/src')->cannotBeEmpty()->end();
        $discovery->scalarNode('namespace')->defaultNull()->end();
        $discovery->arrayNode('excludes')->scalarPrototype()->cannotBeEmpty()->end()->defaultValue([])->end();
        foreach (['modules', 'projects'] as $name) {
            $discovery->arrayNode($name)->arrayPrototype()->children()
                ->scalarNode('path')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('namespace')->defaultNull()->end()
                ->arrayNode('excludes')->scalarPrototype()->cannotBeEmpty()->end()->defaultValue([])->end()
            ->end()->end()->end();
        }
        $discovery->end()->end();
        $children->arrayNode('modules')->useAttributeAsKey('id')->arrayPrototype()->children()
            ->booleanNode('enabled')->defaultTrue()->end()
        ->end()->end()->end();
        $children->arrayNode('projects')->useAttributeAsKey('id')->arrayPrototype()->children()
            ->arrayNode('hosts')->scalarPrototype()->cannotBeEmpty()->end()->defaultValue([])->end()
        ->end()->end()->end();
        $integrations = $children->arrayNode('integrations')->addDefaultsIfNotSet()->children();
        $integrations->arrayNode('controllers')->scalarPrototype()->cannotBeEmpty()->end()->defaultValue(['Controller', '**/Controller'])->end();
        $integrations->arrayNode('entities')->scalarPrototype()->cannotBeEmpty()->end()->defaultValue(['Entity', '**/Entity'])->end();
        $integrations->arrayNode('services')->addDefaultsIfNotSet()->children()
            ->arrayNode('exclude')->scalarPrototype()->cannotBeEmpty()->end()->defaultValue(['Entity/**', '**/Entity/**', 'Dto/**', '**/Dto/**', 'Tests/**', '**/Tests/**', 'Fixtures/**', '**/Fixtures/**', 'Migrations/**', '**/Migrations/**'])->end()
        ->end()->end();
        $integrations->end()->end();
        $children->arrayNode('doctrine')->addDefaultsIfNotSet()
            ->beforeNormalization()->ifTrue(static fn ($value): bool => \is_bool($value))->then(static fn (bool $value): array => ['enabled' => $value])->end()
            ->children()->variableNode('enabled')->defaultNull()
                ->validate()->ifTrue(static fn ($value): bool => null !== $value && !\is_bool($value))->thenInvalid('doctrine.enabled must be true, false or null (automatic).')->end()
            ->end()->end()->end();
        $children->end();

        return $tree;
    }
}
