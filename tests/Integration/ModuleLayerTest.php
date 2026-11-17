<?php

declare(strict_types=1);

namespace Sillbeam\Core\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Sillbeam\Core\Module\ModuleRegistryInterface;
use Sillbeam\Core\Tests\Support\SourceWorkspace;
use Sillbeam\Core\Tests\Support\TestApplicationKernel;
use Symfony\Component\HttpFoundation\Request;

final class ModuleLayerTest extends TestCase
{
    private SourceWorkspace $source;
    private ?TestApplicationKernel $kernel = null;

    protected function setUp(): void
    {
        $this->source = new SourceWorkspace();
        $this->source->write('config/packages/framework.yaml', "framework:\n    secret: test\n    test: true\n    trusted_hosts: ['.*']\n    router: { utf8: true }\n");
        $this->source->write('config/services.yaml', 'services: {}');
        $this->source->write('config/routes.yaml', "sillbeam:\n    resource: .\n    type: sillbeam\n");
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->source->remove();
    }

    public function testMakerCommandsAreRegisteredInSymfonyAndCreateDiscoverableDescriptors(): void
    {
        $this->kernel = new TestApplicationKernel($this->source);
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($this->kernel);
        $application->setAutoExit(false);
        foreach ([['sillbeam:make:module', ['Name' => 'user'], 'User'], ['sillbeam:make:project', ['Name' => 'shop'], 'Shop'], ['sillbeam:make:module', ['Name' => 'checkout', '--project' => 'Shop'], 'Checkout']] as [$name, $input, $effectiveName]) {
            $tester = new \Symfony\Component\Console\Tester\CommandTester($application->find($name));
            self::assertSame(0, $tester->execute($input), $tester->getDisplay());
            self::assertStringContainsString($effectiveName, $tester->getDisplay());
            self::assertSame(1, $tester->execute($input));
            self::assertStringContainsString('already exists', $tester->getDisplay());
            foreach (['123user', 'user-profile', '../user', 'user name', 'user_name', 'Foo\\Bar'] as $invalid) {
                self::assertSame(1, $tester->execute(array_replace($input, ['Name' => $invalid])));
            }
        }
        $container = new \Symfony\Component\DependencyInjection\ContainerBuilder();
        $container->setParameter('kernel.project_dir', $this->source->directory);
        $options = (new \Sillbeam\Core\Architecture\DiscoveryOptionsResolver())->resolve($container, []);
        $discovery = (new \Sillbeam\Core\Architecture\ArchitectureDiscovery())->discover($options);
        self::assertCount(2, $discovery->modules);
        self::assertCount(1, $discovery->projects);
    }

    public function testMakerCommandsNormalizeWithinCustomRootsAndKeepProjectOwnership(): void
    {
        mkdir($this->source->directory.'/src/Features');
        mkdir($this->source->directory.'/src/Sites');
        $this->source->write('config/packages/sillbeam.yaml', "sillbeam:\n    discovery:\n        modules: [{ path: src/Features }]\n        projects: [{ path: src/Sites }]\n");
        $this->kernel = new TestApplicationKernel($this->source);
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($this->kernel);
        $application->setAutoExit(false);
        foreach ([
            ['sillbeam:make:module', ['Name' => 'userProfile'], 'Features/UserProfile/UserProfileModule.php', 'Features\\UserProfile', 'UserProfileModule'],
            ['sillbeam:make:project', ['Name' => 'shop'], 'Sites/Shop/ShopProject.php', 'Sites\\Shop', 'ShopProject'],
            ['sillbeam:make:module', ['Name' => 'checkout', '--project' => 'shop'], 'Sites/Shop/Module/Checkout/CheckoutModule.php', 'Sites\\Shop\\Module\\Checkout', 'CheckoutModule'],
        ] as [$name, $input, $relative, $namespace, $class]) {
            $tester = new \Symfony\Component\Console\Tester\CommandTester($application->find($name));
            self::assertSame(0, $tester->execute($input), $tester->getDisplay());
            $file = $this->source->directory.'/src/'.$relative;
            $display = preg_replace('/\s+/', '', $tester->getDisplay());
            self::assertIsString($display);
            self::assertStringContainsString($file, $display);
            $contents = file_get_contents($file);
            self::assertIsString($contents);
            self::assertStringContainsString('namespace '.$this->source->namespace.'\\'.$namespace.';', $contents);
            self::assertStringContainsString('final class '.$class, $contents);
            self::assertSame(1, $tester->execute(array_replace($input, ['Name' => ucfirst($input['Name'])])));
            self::assertStringContainsString('already exists', $tester->getDisplay());
            self::assertSame($contents, file_get_contents($file));
        }
        self::assertDirectoryDoesNotExist($this->source->directory.'/src/Module');
        self::assertDirectoryDoesNotExist($this->source->directory.'/src/Project');
    }

    public function testEmptySillbeamConfigWorksWithoutDoctrineOrFoundation(): void
    {
        $module = $this->source->php('src/Module/Hello/HelloModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class HelloModule {}');
        $this->source->php('src/Module/Hello/Controller/HelloController.php', 'final class HelloController { #[\\Symfony\\Component\\Routing\\Attribute\\Route("/hello", name: "hello")] public function hello(): \\Symfony\\Component\\HttpFoundation\\Response { return new \\Symfony\\Component\\HttpFoundation\\Response("hello"); } }');
        $this->kernel = new TestApplicationKernel($this->source);
        $this->kernel->boot();
        $response = $this->kernel->handle(Request::create('/hello'));
        self::assertSame('hello', $response->getContent());
        self::assertFalse($response->headers->has('X-Request-Id'));
        $registry = $this->kernel->getContainer()->get(ModuleRegistryInterface::class);
        self::assertInstanceOf(ModuleRegistryInterface::class, $registry);
        self::assertTrue($registry->get($module)->enabled);
    }

    public function testContextPropagatesUnknownHostLookupWithoutBlockingNativeRoutes(): void
    {
        $this->source->php('src/Project/Shop/ShopProject.php', '#[\\Sillbeam\\Core\\Project\\Attribute\\SillbeamProject] final class ShopProject {}');
        $this->source->write('config/packages/sillbeam.yaml', "sillbeam:\n    projects:\n        shop: { hosts: [shop.test] }\n");
        $controller = $this->source->php('src/Controller/Health.php', 'final class Health { #[\\Symfony\\Component\\Routing\\Attribute\\Route("/health", name: "health")] public function run(\\Sillbeam\\Core\\Project\\ProjectContextInterface $context): \\Symfony\\Component\\HttpFoundation\\Response { try { $id = $context->id(); } catch (\\OutOfBoundsException) { $id = "outside"; } return new \\Symfony\\Component\\HttpFoundation\\Response($id); } }');
        $this->source->write('config/services.yaml', "services:\n    '".$controller."':\n        autowire: true\n        tags: [controller.service_arguments]\n");
        $this->source->write('config/routes.yaml', "native:\n    resource: ../src/Controller/Health.php\n    type: attribute\nsillbeam:\n    resource: .\n    type: sillbeam\n");
        $this->kernel = new TestApplicationKernel($this->source);
        self::assertSame('outside', $this->kernel->handle(Request::create('https://unknown.test/health'))->getContent());
        self::assertSame('shop', $this->kernel->handle(Request::create('https://shop.test/health'))->getContent());
    }

    public function testDoctrineOnlyMapsActiveModulesAndKeepsNativeMapping(): void
    {
        $live = $this->source->php('src/Module/Live/LiveModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class LiveModule {}');
        $this->source->php('src/Module/Off/OffModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class OffModule {}');
        $body = '#[\\Doctrine\\ORM\\Mapping\\Entity] final class Record { #[\\Doctrine\\ORM\\Mapping\\Id] #[\\Doctrine\\ORM\\Mapping\\Column(type: "integer")] public int $id = 1; }';
        $active = $this->source->php('src/Module/Live/Entity/Record.php', $body);
        $disabled = $this->source->php('src/Module/Off/Entity/Record.php', $body);
        $unused = $this->source->php('src/Module/Unused/Entity/Record.php', $body);
        $this->source->php('src/Module/Unused/UnusedModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class UnusedModule {}');
        $legacy = $this->source->php('src/Entity/Record.php', $body);
        $this->source->php('src/Project/Shop/ShopProject.php', '#[\\Sillbeam\\Core\\Project\\Attribute\\SillbeamProject(modules: [\\'.$live.'::class])] final class ShopProject {}');
        $this->source->write('config/packages/sillbeam.yaml', "sillbeam:\n    modules:\n        off: { enabled: false }\n");
        $this->source->write('config/packages/doctrine.yaml', "doctrine:\n    dbal: { url: 'sqlite:///:memory:' }\n    orm:\n        auto_mapping: false\n        mappings:\n            Legacy:\n                type: attribute\n                is_bundle: false\n                dir: '%kernel.project_dir%/src/Entity'\n                prefix: '".$this->source->namespace."\\Entity'\n");
        $this->kernel = new TestApplicationKernel($this->source, true);
        $this->kernel->boot();
        $manager = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $classes = array_map(static fn ($metadata): string => $metadata->getName(), $manager->getMetadataFactory()->getAllMetadata());
        self::assertContains($active, $classes);
        self::assertContains($legacy, $classes);
        self::assertNotContains($disabled, $classes);
        self::assertNotContains($unused, $classes);
        self::assertCount(2, $classes);
    }

    public function testNativeDoctrineOverrideOfGeneratedMappingRemainsAuthoritative(): void
    {
        $this->source->php('src/Module/Live/LiveModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class LiveModule {}');
        $body = '#[\\Doctrine\\ORM\\Mapping\\Entity] final class Record { #[\\Doctrine\\ORM\\Mapping\\Id] #[\\Doctrine\\ORM\\Mapping\\Column(type: "integer")] public int $id = 1; }';
        $selected = $this->source->php('src/Module/Live/Entity/Selected/Record.php', $body);
        $ignored = $this->source->php('src/Module/Live/Entity/Record.php', $body);
        $directory = $this->source->directory.'/src/Module/Live/Entity';
        $key = 'Sillbeam'.hash('xxh128', $directory);
        $this->source->write('config/packages/doctrine.yaml', "doctrine:\n    dbal: { url: 'sqlite:///:memory:' }\n    orm:\n        auto_mapping: false\n        mappings:\n            ".$key.":\n                type: attribute\n                is_bundle: false\n                dir: '".$directory."/Selected'\n                prefix: '".$this->source->namespace."\\Module\\Live\\Entity'\n");
        $this->kernel = new TestApplicationKernel($this->source, true);
        $this->kernel->boot();
        $manager = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $classes = array_map(static fn ($metadata): string => $metadata->getName(), $manager->getMetadataFactory()->getAllMetadata());
        self::assertContains($selected, $classes);
        self::assertNotContains($ignored, $classes);
        self::assertCount(1, $classes);
    }

    public function testNativeServiceOverrideWinsOverModuleDefaults(): void
    {
        $this->source->php('src/Module/Hello/HelloModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class HelloModule {}');
        $service = $this->source->php('src/Module/Hello/Greeting.php', 'final class Greeting { public function __construct(public string $text = "default") {} }');
        $this->source->write('config/services.yaml', "services:\n    '".$service."':\n        public: true\n        arguments: ['native']\n");
        $this->source->write('config/packages/sillbeam.yaml', "sillbeam: {}\n");
        $this->kernel = new TestApplicationKernel($this->source);
        $this->kernel->boot();
        /** @var object{text: string} $greeting */
        $greeting = $this->kernel->getContainer()->get($service);
        self::assertSame('native', $greeting->text);
    }

    public function testArchitectureCommandIncludesInactiveCatalogAndJsonEvidence(): void
    {
        $this->source->php('src/Module/Off/OffModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class OffModule {}');
        $this->source->write('config/packages/sillbeam.yaml', "sillbeam:\n    modules:\n        off: { enabled: false }\n");
        $this->kernel = new TestApplicationKernel($this->source);
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($this->kernel);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($application->find('sillbeam:architecture'));
        self::assertSame(0, $tester->execute(['--format' => 'json']));
        $data = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('off', $data['modules'][0]['id']);
        self::assertFalse($data['modules'][0]['enabled']);
        self::assertFalse($data['modules'][0]['active']);
        self::assertSame('disabled', $data['modules'][0]['inactive_reason']);
        self::assertSame([], $data['modules'][0]['observed_dependencies']);
    }

    public function testExcludedCustomRootControllerDoesNotLeakIntoRoutes(): void
    {
        $this->source->php('src/Domain/Hello/Hello.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule(id: "hello")] final class Hello {}');
        $controller = '#[\\Symfony\\Component\\DependencyInjection\\Attribute\\Autoconfigure(public: true)] final class Endpoint { #[\\Symfony\\Component\\Routing\\Attribute\\Route("/hidden", name: "hidden")] public function run(): \\Symfony\\Component\\HttpFoundation\\Response { return new \\Symfony\\Component\\HttpFoundation\\Response("hidden"); } }';
        $endpoint = $this->source->php('src/Domain/Hello/Http/Hidden/Endpoint.php', $controller);
        $this->source->write('config/packages/sillbeam.yaml', "sillbeam:\n    discovery:\n        modules:\n            - path: src/Domain\n              excludes: ['Hello/Http/Hidden/**']\n    integrations:\n        controllers: [Http]\n");
        $this->kernel = new TestApplicationKernel($this->source);
        $this->kernel->boot();
        $router = $this->kernel->getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);
        self::assertNull($router->getRouteCollection()->get('hidden'));
        self::assertFalse($this->kernel->getContainer()->has($endpoint));
    }

    public function testExcludedEntitiesAndDisabledPrivateAreasDoNotLeakThroughParentMappings(): void
    {
        $this->source->php('src/Project/Shop/ShopProject.php', '#[\\Sillbeam\\Core\\Project\\Attribute\\SillbeamProject] final class ShopProject {}');
        $this->source->php('src/Project/Shop/Entity/Private/OffModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule(id: "off")] final class OffModule {}');
        $body = '#[\\Doctrine\\ORM\\Mapping\\Entity] final class Record { #[\\Doctrine\\ORM\\Mapping\\Id] #[\\Doctrine\\ORM\\Mapping\\Column(type: "integer")] public int $id = 1; }';
        $live = $this->source->php('src/Project/Shop/Entity/Record.php', $body);
        $off = $this->source->php('src/Project/Shop/Entity/Private/Entity/Record.php', $body);
        $hidden = $this->source->php('src/Project/Shop/Entity/Hidden/Record.php', $body);
        $this->source->write('config/packages/sillbeam.yaml', "sillbeam:\n    discovery:\n        excludes: ['Project/Shop/Entity/Hidden/**']\n    modules:\n        'project:shop:off': { enabled: false }\n");
        $this->source->write('config/packages/doctrine.yaml', "doctrine:\n    dbal: { url: 'sqlite:///:memory:' }\n    orm: { auto_mapping: false }\n");
        $this->kernel = new TestApplicationKernel($this->source, true);
        $this->kernel->boot();
        $manager = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $classes = array_map(static fn ($metadata): string => $metadata->getName(), $manager->getMetadataFactory()->getAllMetadata());
        self::assertContains($live, $classes);
        self::assertNotContains($off, $classes);
        self::assertNotContains($hidden, $classes);
        self::assertCount(1, $classes);
    }

    public function testExactDirectoryExclusionsPruneEntitiesFromEnclosingMappings(): void
    {
        $this->source->php('src/Project/Shop/ShopProject.php', '#[\\Sillbeam\\Core\\Project\\Attribute\\SillbeamProject] final class ShopProject {}');
        $body = '#[\\Doctrine\\ORM\\Mapping\\Entity] final class Record { #[\\Doctrine\\ORM\\Mapping\\Id] #[\\Doctrine\\ORM\\Mapping\\Column(type: "integer")] public int $id = 1; }';
        $live = $this->source->php('src/Project/Shop/Entity/Record.php', $body);
        $hidden = $this->source->php('src/Project/Shop/Entity/Hidden/Record.php', $body);
        $this->source->write('config/packages/sillbeam.yaml', "sillbeam: { discovery: { excludes: ['Project/Shop/Entity/Hidden'] } }");
        $this->source->write('config/packages/doctrine.yaml', "doctrine: { dbal: { url: 'sqlite:///:memory:' }, orm: { auto_mapping: false } }");
        $this->kernel = new TestApplicationKernel($this->source, true);
        $this->kernel->boot();
        $manager = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $classes = array_map(static fn ($metadata): string => $metadata->getName(), $manager->getMetadataFactory()->getAllMetadata());
        self::assertContains($live, $classes);
        self::assertNotContains($hidden, $classes);
        self::assertCount(1, $classes);
    }

    public function testNativeDoctrineOverrideCanMapAnExplicitlyExcludedDirectory(): void
    {
        $this->source->php('src/Module/Live/LiveModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class LiveModule {}');
        $hidden = $this->source->php('src/Module/Live/Entity/Hidden/Record.php', '#[\\Doctrine\\ORM\\Mapping\\Entity] final class Record { #[\\Doctrine\\ORM\\Mapping\\Id] #[\\Doctrine\\ORM\\Mapping\\Column(type: "integer")] public int $id = 1; }');
        $directory = $this->source->directory.'/src/Module/Live/Entity';
        $key = 'Sillbeam'.hash('xxh128', $directory);
        $this->source->write('config/packages/sillbeam.yaml', "sillbeam: { discovery: { excludes: ['Module/Live/Entity/Hidden'] } }");
        $this->source->write('config/packages/doctrine.yaml', "doctrine:\n    dbal: { url: 'sqlite:///:memory:' }\n    orm:\n        auto_mapping: false\n        mappings:\n            ".$key.":\n                type: attribute\n                is_bundle: false\n                dir: '".$directory."/Hidden'\n                prefix: '".$this->source->namespace."\\Module\\Live\\Entity'\n");
        $this->kernel = new TestApplicationKernel($this->source, true);
        $this->kernel->boot();
        $manager = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        self::assertSame([$hidden], array_map(static fn ($metadata): string => $metadata->getName(), $manager->getMetadataFactory()->getAllMetadata()));
    }

    public function testBuildTimeEnvironmentActivationIsResolvedBeforeCompilation(): void
    {
        $module = $this->source->php('src/Module/Off/OffModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class OffModule {}');
        $this->source->write('config/packages/sillbeam.yaml', "sillbeam:\n    modules:\n        off: { enabled: '%env(bool:SILLBEAM_TEST_ENABLED)%' }\n");
        $_ENV['SILLBEAM_TEST_ENABLED'] = '0';
        try {
            $this->kernel = new TestApplicationKernel($this->source);
            $this->kernel->boot();
            $registry = $this->kernel->getContainer()->get(ModuleRegistryInterface::class);
            self::assertInstanceOf(ModuleRegistryInterface::class, $registry);
            self::assertFalse($registry->get($module)->enabled);
            self::assertSame([], $registry->active());
        } finally {
            unset($_ENV['SILLBEAM_TEST_ENABLED']);
        }
    }

    public function testRoutingResourcesDetectCreationOfMissingDefaultRoot(): void
    {
        $this->kernel = new TestApplicationKernel($this->source);
        $this->kernel->boot();
        $router = $this->kernel->getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);
        $resources = array_filter($router->getRouteCollection()->getResources(), fn ($resource): bool => $resource instanceof \Symfony\Component\Config\Resource\FileExistenceResource && $resource->getResource() === $this->source->directory.'/src/Module');
        self::assertCount(1, $resources);
        $resource = array_values($resources)[0];
        self::assertInstanceOf(\Symfony\Component\Config\Resource\FileExistenceResource::class, $resource);
        self::assertTrue($resource->isFresh(time()));
        $this->source->php('src/Module/NewModule/NewModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class NewModule {}');
        self::assertFalse($resource->isFresh(time()));
    }

    public function testRoutingResourcesTrackAdditionsAndDeletionsInCustomRoot(): void
    {
        $path = $this->source->directory.'/packages/Modules';
        mkdir($path, 0777, true);
        $this->source->write('config/packages/sillbeam.yaml', "sillbeam:\n    discovery:\n        modules:\n            - { path: packages/Modules, namespace: External }\n");
        $this->kernel = new TestApplicationKernel($this->source);
        $this->kernel->boot();
        $router = $this->kernel->getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);
        $resources = array_filter($router->getRouteCollection()->getResources(), static fn ($resource): bool => $resource instanceof \Symfony\Component\Config\Resource\DirectoryResource && $resource->getResource() === $path);
        self::assertCount(1, $resources);
        $resource = array_values($resources)[0];
        self::assertInstanceOf(\Symfony\Component\Config\Resource\DirectoryResource::class, $resource);
        $timestamp = time() + 10;
        self::assertTrue($resource->isFresh($timestamp));
        $this->source->write('packages/Modules/Added.php', '<?php namespace External; final class Added {}');
        touch($path.'/Added.php', $timestamp + 1);
        clearstatcache();
        self::assertFalse($resource->isFresh($timestamp));
        unlink($path.'/Added.php');
        touch($path, $timestamp + 1);
        clearstatcache();
        self::assertFalse($resource->isFresh($timestamp));
    }

    public function testRootLocalExclusionsAlsoApplyToEnclosingProjectServiceImport(): void
    {
        $this->source->php('src/Project/Shop/ShopProject.php', '#[\Sillbeam\Core\Project\Attribute\SillbeamProject] final class ShopProject {}');
        $this->source->php('src/Project/Shop/Extension/Hidden/Leak.php', '#[\Symfony\Component\DependencyInjection\Attribute\AsAlias(id: "leak", public: true)] final class Leak {}');
        $this->source->write('config/packages/sillbeam.yaml', "sillbeam:\n    discovery:\n        modules:\n            - path: src/Project/Shop/Extension\n              excludes: ['Hidden/**']\n");
        $this->kernel = new TestApplicationKernel($this->source);
        $this->kernel->boot();
        self::assertFalse($this->kernel->getContainer()->has('leak'));
    }

    public function testRoutingResourcesInvalidateWhenArchitectureConfigOrComposerChanges(): void
    {
        $this->source->php('src/Module/Hello/HelloModule.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule] final class HelloModule {}');
        $this->source->php('src/Module/Hello/Controller/Endpoint.php', 'final class Endpoint { #[\\Symfony\\Component\\Routing\\Attribute\\Route("/hello", name: "hello")] public function run(): void {} }');
        $this->source->write('config/packages/sillbeam.yaml', "sillbeam: {}\n");
        $this->kernel = new TestApplicationKernel($this->source);
        $this->kernel->boot();
        $router = $this->kernel->getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);
        $cache = new \Symfony\Component\Config\ConfigCache($this->source->directory.'/route-freshness.php', true);
        $cache->write('<?php return true;', $router->getRouteCollection()->getResources());
        $timestamp = time() + 10;
        foreach (['config/packages/sillbeam.yaml', 'config/routes.yaml', 'composer.json', 'src/Module/Hello/HelloModule.php', 'src/Module/Hello/Controller/Endpoint.php'] as $file) {
            $path = $this->source->directory.'/'.$file;
            touch($cache->getPath(), ++$timestamp);
            clearstatcache();
            self::assertTrue($cache->isFresh(), 'The route cache must be fresh before editing '.$file.'.');

            touch($path, $timestamp + 2);
            touch($cache->getPath(), ++$timestamp);
            clearstatcache();
            self::assertFalse($cache->isFresh(), $file.' must invalidate the route cache.');

            touch($path, $timestamp - 1);
            clearstatcache();
        }
    }
}
