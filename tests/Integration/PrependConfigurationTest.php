<?php

declare(strict_types=1);

namespace Sillbeam\Core\Tests\Integration;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sillbeam\Core\Console\KernelArchitectureReporter;
use Sillbeam\Core\Module\ModuleRegistryInterface;
use Sillbeam\Core\SillbeamCoreBundle;
use Sillbeam\Core\Tests\Support\SourceWorkspace;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\HttpKernel\Kernel;

final class PrependConfigurationTest extends TestCase
{
    /** @return iterable<string, array{bool, bool, string, array<string, mixed>, string, bool}> */
    public static function configurations(): iterable
    {
        $changes = [
            'activation' => ['modules' => ['live' => ['enabled' => false]]],
            'discovery' => ['discovery' => ['excludes' => ['Module/Live/**']]],
            'services' => ['integrations' => ['services' => ['exclude' => ['Service.php']]]],
            'entities' => ['integrations' => ['entities' => ['Model']]],
            'doctrine' => ['doctrine' => false],
            'locale' => ['locale' => 'de'],
            'unchanged' => [],
        ];
        foreach ([false, true] as $doctrine) {
            foreach ([true, false] as $before) {
                foreach ($changes as $case => $configuration) {
                    foreach (['dev' => true, 'prod' => false] as $environment => $debug) {
                        yield $environment.' '.($doctrine ? 'Doctrine' : 'no Doctrine').' '.($before ? 'early' : 'late').' '.$case => [$doctrine, $before, $case, $configuration, $environment, $debug];
                    }
                }
            }
        }
    }

    /** @param array<string, mixed> $configuration */
    #[DataProvider('configurations')]
    public function testFinalConfigurationIsAppliedOrExplicitlyRejected(bool $doctrine, bool $before, string $case, array $configuration, string $environment, bool $debug): void
    {
        $source = new SourceWorkspace();
        $kernel = $this->kernel($source, $doctrine, $before, $configuration, $environment, $debug);
        try {
            $module = $source->php('src/Module/Live/LiveModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class LiveModule {}');
            $source->php('src/Module/Live/Service.php', '#[\\Symfony\\Component\\DependencyInjection\\Attribute\\AsAlias(id: "live", public: true)] final class Service {}');
            $entity = '#[\\Doctrine\\ORM\\Mapping\\Entity] final class Record { #[\\Doctrine\\ORM\\Mapping\\Id] #[\\Doctrine\\ORM\\Mapping\\Column] public int $id = 1; }';
            $standardEntity = $source->php('src/Module/Live/Entity/Record.php', $entity);
            $customEntity = $source->php('src/Module/Live/Model/Record.php', $entity);
            $source->write('config/packages/framework.yaml', 'framework: { secret: test }');
            $source->write('config/services.yaml', 'services: {}');
            if ($doctrine) {
                $source->write('config/packages/doctrine.yaml', "doctrine: { dbal: { url: 'sqlite:///:memory:' }, orm: { auto_mapping: false } }");
            }
            if ($doctrine && !$before && !\in_array($case, ['unchanged', 'locale'], true)) {
                $this->expectException(\LogicException::class);
                $this->expectExceptionMessage('Sillbeam configuration changed after prepend()');
                $kernel->boot();

                return;
            }
            $kernel->boot();
            if ('locale' === $case) {
                self::assertSame('de', (new KernelArchitectureReporter())->projectLocale($kernel));
            }
            $container = $kernel->getContainer();
            $registry = $container->get(ModuleRegistryInterface::class);
            self::assertInstanceOf(ModuleRegistryInterface::class, $registry);
            $active = !\in_array($case, ['activation', 'discovery'], true);
            self::assertSame($active, isset($registry->active()[$module]));
            self::assertSame($active && 'services' !== $case, $container->has('live'));
            if ($doctrine) {
                $manager = $container->get('doctrine.orm.entity_manager');
                self::assertInstanceOf(EntityManagerInterface::class, $manager);
                $classes = array_map(static fn ($metadata): string => $metadata->getName(), $manager->getMetadataFactory()->getAllMetadata());
                self::assertSame($active && !\in_array($case, ['entities', 'doctrine'], true), \in_array($standardEntity, $classes, true));
                self::assertSame($active && 'entities' === $case, \in_array($customEntity, $classes, true));
            }
        } finally {
            $kernel->shutdown();
            $source->remove();
        }
    }

    public function testLateDeactivationIsAppliedBeforeCodeDependencyValidationWithoutDoctrine(): void
    {
        $source = new SourceWorkspace();
        $kernel = $this->kernel($source, false, false, ['modules' => ['live' => ['enabled' => false]]], 'prod', false);
        try {
            $module = $source->php('src/Module/Live/LiveModule.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule] final class LiveModule {}');
            $source->php('src/Module/Other/OtherModule.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule] final class OtherModule {}');
            $target = $source->php('src/Module/Other/Value.php', 'final class Value {}');
            $source->php('src/Module/Live/Consumer.php', 'final class Consumer { public function target(): string { return \\'.$target.'::class; } }');
            $source->write('config/packages/framework.yaml', 'framework: { secret: test }');
            $source->write('config/services.yaml', 'services: {}');
            $kernel->boot();
            $registry = $kernel->getContainer()->get(ModuleRegistryInterface::class);
            self::assertInstanceOf(ModuleRegistryInterface::class, $registry);
            self::assertFalse($registry->get($module)->enabled);
        } finally {
            $kernel->shutdown();
            $source->remove();
        }
    }

    /** @param array<string, mixed> $configuration */
    private function kernel(SourceWorkspace $source, bool $doctrine, bool $before, array $configuration, string $environment, bool $debug): Kernel
    {
        $bundle = new ConfigurationPrependingBundle($configuration);

        return new class($source, $doctrine, $before, $bundle, $environment, $debug) extends Kernel {
            use MicroKernelTrait;

            public function __construct(private readonly SourceWorkspace $source, private readonly bool $doctrine, private readonly bool $before, private readonly Bundle $bundle, string $environment, bool $debug)
            {
                parent::__construct($environment, $debug);
            }

            public function getProjectDir(): string
            {
                return $this->source->directory;
            }

            protected function getContainerClass(): string
            {
                return $this->source->namespace.'PrependContainer';
            }

            public function registerBundles(): iterable
            {
                yield new FrameworkBundle();
                if ($this->doctrine) {
                    yield new DoctrineBundle();
                }
                if ($this->before) {
                    yield $this->bundle;
                }
                yield new SillbeamCoreBundle();
                if (!$this->before) {
                    yield $this->bundle;
                }
            }
        };
    }
}

final class ConfigurationPrependingBundle extends Bundle
{
    /** @param array<string, mixed> $configuration */
    public function __construct(private readonly array $configuration)
    {
    }

    public function getContainerExtension(): ExtensionInterface
    {
        return new class($this->configuration) extends Extension implements PrependExtensionInterface {
            /** @param array<string, mixed> $configuration */
            public function __construct(private readonly array $configuration)
            {
            }

            public function getAlias(): string
            {
                return 'configuration_prepending';
            }

            public function prepend(ContainerBuilder $container): void
            {
                $container->prependExtensionConfig('sillbeam', $this->configuration);
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }
        };
    }
}
