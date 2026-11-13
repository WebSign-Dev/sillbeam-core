<?php

declare(strict_types=1);

namespace Sillbeam\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sillbeam\Core\Architecture\ArchitectureDiscovery;
use Sillbeam\Core\Architecture\AreaDirectoryMap;
use Sillbeam\Core\Architecture\DiscoveryOptions;
use Sillbeam\Core\Architecture\DiscoveryOptionsResolver;
use Sillbeam\Core\Architecture\DiscoveryRoot;
use Sillbeam\Core\Architecture\SourceInspector;
use Sillbeam\Core\Tests\Support\SourceWorkspace;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class PathConsistencyTest extends TestCase
{
    public function testRootMembershipUsesConsistentSeparatorsAndDirectoryBoundaries(): void
    {
        $root = new DiscoveryRoot('/app\\src/Module/', 'App\\Module\\');
        self::assertSame('/app/src/Module', $root->path);
        self::assertTrue($root->contains('/app/src\\Module/Billing'));
        self::assertFalse($root->contains('/app/src/Modules/Billing'));
    }

    public function testFilesystemRootContainsDescendants(): void
    {
        self::assertTrue((new DiscoveryRoot('/', 'App\\'))->contains('/src/File.php'));
        $options = new DiscoveryOptions('/', 'App\\', excludes: ['Generated/**']);
        self::assertTrue($options->excluded('/Generated/File.php'));
        self::assertSame('/Module', $options->moduleRoots[0]->path);
        self::assertSame('App\\Module\\Billing', $options->namespaceForDirectory('/Module/Billing'));
    }

    public function testDriveRootRetainsItsRootSeparator(): void
    {
        $root = new DiscoveryRoot('C:\\', 'App\\');
        self::assertSame('C:/', $root->path);
        self::assertTrue($root->contains('C:/src/File.php'));
    }

    public function testAbsoluteDrivePathIsNotResolvedRelativeToProjectDirectory(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', '/app');
        $options = (new DiscoveryOptionsResolver())->resolve($container, [['discovery' => ['root' => 'C:\\source', 'namespace' => 'App\\']]]);
        self::assertSame('C:/source', $options->root);
    }

    public function testRelativeRootAndComposerMappingAcceptMixedSeparators(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->php('src/Domain/Example.php', 'final class Example {}');
            $source->write('composer.json', json_encode(['autoload' => ['psr-4' => [$source->namespace.'\\' => 'src\\']]], \JSON_THROW_ON_ERROR));
            $container = new ContainerBuilder();
            $container->setParameter('kernel.project_dir', str_replace('/', '\\', $source->directory));
            $options = (new DiscoveryOptionsResolver())->resolve($container, [['discovery' => ['root' => 'src\\Domain/']]]);
            self::assertSame($source->directory.'/src/Domain', $options->root);
            self::assertSame($source->namespace.'\\Domain\\', $options->namespace);
        } finally {
            $source->remove();
        }
    }

    public function testRelativeExclusionsAndNamespaceLookupNormalizeInput(): void
    {
        $options = new DiscoveryOptions('/app\\src/', 'App\\', [new DiscoveryRoot('/app/src\\Module/', 'App\\Module\\', ['Ignored/**'])], [], ['Generated/**']);
        self::assertTrue($options->excluded('/app/src\\Generated/File.php'));
        self::assertTrue($options->excluded('/app\\src/Module/Ignored/File.php'));
        self::assertSame('App\\Module\\Billing', $options->namespaceForDirectory('/app\\src/Module\\Billing/'));
    }

    public function testOverlappingRootsProduceOneCanonicalIndexAndRespectPrunedDirectories(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->php('src/Module/Billing/Billing.php', 'final class Billing {}');
            $source->php('src/Module/Ignored/Broken.php', 'invalid PHP !!!');
            $options = new DiscoveryOptions(str_replace('/', '\\', $source->directory).'/src/', $source->namespace.'\\', [new DiscoveryRoot($source->directory.'/src\\Module/', $source->namespace.'\\Module\\')], [], ['Module/Ignored/**']);
            $inspector = new SourceInspector();
            self::assertSame([$source->directory.'/src/Module/Billing/Billing.php'], array_keys($inspector->files($options)));
            self::assertSame([$source->directory.'/src/Module/Ignored/'], $inspector->excludedPaths($options));
        } finally {
            $source->remove();
        }
    }

    public function testMixedProjectRootFindsItsPrivateModuleAndCanonicalDescriptorPaths(): void
    {
        $source = new SourceWorkspace();
        try {
            $project = $source->php('src/Project/Shop/Shop.php', '#[\\Sillbeam\\Core\\Project\\Attribute\\SillbeamProject] final class Shop {}');
            $module = $source->php('src/Project/Shop/Billing/Billing.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class Billing {}');
            $options = new DiscoveryOptions($source->directory.'/src', $source->namespace.'\\', [], [new DiscoveryRoot($source->directory.'/src\\Project/', $source->namespace.'\\Project\\')]);
            $result = (new ArchitectureDiscovery())->discover($options);
            self::assertSame($source->directory.'/src/Project/Shop', $result->projects[$project]->directory);
            self::assertSame($project, $result->modules[$module]->projectClass);
            self::assertSame($source->directory.'/src/Project/Shop/Billing/Billing.php', $result->modules[$module]->descriptorFile);
        } finally {
            $source->remove();
        }
    }

    public function testDirectoryGroupsPreserveEmptyDirectoriesExclusionsAndOrder(): void
    {
        $source = new SourceWorkspace();
        try {
            $root = $source->directory.'/src';
            mkdir($root.'/Z/Controller', recursive: true);
            mkdir($root.'/A/Entity', recursive: true);
            mkdir($root.'/Private/Controller', recursive: true);
            mkdir($root.'/Ignored/Entity', recursive: true);
            $options = new DiscoveryOptions($root, $source->namespace.'\\', excludes: ['Ignored']);
            self::assertSame([
                'controllers' => [$root.'/Z/Controller'],
                'entities' => [$root.'/A/Entity'],
                'both' => [$root.'/A/Entity', $root.'/Z/Controller'],
            ], (new SourceInspector())->directoryGroups($root, [
                'controllers' => ['**/Controller'],
                'entities' => ['**/Entity'],
                'both' => ['**/Controller', '**/Entity'],
            ], [$root.'/Private'], $options));
        } finally {
            $source->remove();
        }
    }

    public function testAreaLookupKeepsProjectMembershipAndPrefersPrivateModules(): void
    {
        $areas = new AreaDirectoryMap([
            '/src/Shop' => 'Project',
            '/src/Shop/Private' => 'Module',
        ]);
        self::assertSame(['Module', 'Project'], iterator_to_array($areas->containing('/src/Shop/Private/Controller/Home.php'), false));
        self::assertSame(['Project'], iterator_to_array($areas->containing('/src/Shop/Private'), false));
        self::assertSame(['Module', 'Project'], iterator_to_array($areas->containing('/src/Shop/Private/'), false));
        self::assertSame([], iterator_to_array($areas->containing('/src/Shopper/Private/Home.php'), false));
        self::assertSame('Module', $areas->owner('/src/Shop/Private/Controller/Home.php'));
        self::assertNull($areas->owner('/src/Shopper/Home.php'));
    }

    public function testDirectoryScanNormalizesRootAndExcludedRoots(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->php('src/Module/Billing/Controller/Home.php', 'final class Home {}');
            $source->php('src/Controller/Home.php', 'final class Home {}');
            self::assertSame([$source->directory.'/src/Controller'], (new SourceInspector())->directories(str_replace('/', '\\', $source->directory).'/src/', ['**/Controller'], [$source->directory.'/src\\Module/']));
        } finally {
            $source->remove();
        }
    }
}
