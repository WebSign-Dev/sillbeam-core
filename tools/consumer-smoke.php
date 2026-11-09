<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Composer\InstalledVersions;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Sillbeam\Core\Console\Application;
use Sillbeam\Core\Module\ModuleRegistryInterface;
use Sillbeam\Core\Project\ProjectRegistryInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;

[$script, $scenario, $namespace, $withDoctrine] = $argv;
$kernelClass = $namespace.'\\Kernel';
/** @var KernelInterface $kernel */
$kernel = new $kernelClass(isset($argv[4]) ? 'invalid' : 'test', true);
if (isset($argv[4])) {
    try {
        $kernel->boot();
        consumerAssert(!$kernel->getContainer()->has(ModuleRegistryInterface::class), 'Invalid architecture registered a runtime graph.');
        $tester = new CommandTester((new Application($kernel))->find('sillbeam:architecture'));
        consumerAssert(1 === $tester->execute(['--format' => 'json']), 'Consumer accepted an active reference to a disabled module.');
        consumerAssert(str_contains($tester->getDisplay(), 'references an inactive module'), $tester->getDisplay());
    } finally {
        $kernel->shutdown();
    }
    exit(0);
}

consumerAssert('0.3.0' === InstalledVersions::getPrettyVersion('websign-dev/sillbeam-core'), 'Wrong simulated Core release version.');
consumerAssert(!InstalledVersions::isInstalled('phpunit/phpunit'), 'Root dev dependencies leaked into the consumer.');
consumerAssert(!class_exists('Sillbeam\\Core\\Tests\\Support\\SourceWorkspace'), 'Root dev autoload leaked into the consumer.');
consumerAssert(InstalledVersions::isInstalled('doctrine/doctrine-bundle') === ('1' === $withDoctrine), 'Unexpected Doctrine installation.');
if ($symfony = getenv('SILLBEAM_SYMFONY_VERSION')) {
    foreach (InstalledVersions::getInstalledPackages() as $package) {
        $version = InstalledVersions::getVersion($package) ?? '';
        if (str_starts_with($package, 'symfony/') && preg_match('/^[78]\./', $version)) {
            consumerAssert(str_starts_with($version, substr($symfony, 0, -1)), 'Unexpected Symfony component version: '.$package.' '.$version);
        }
    }
}
$kernel->boot();
try {
    $container = $kernel->getContainer();
    $modules = $container->get(ModuleRegistryInterface::class);
    $projects = $container->get(ProjectRegistryInterface::class);
    consumerAssert($modules instanceof ModuleRegistryInterface && $projects instanceof ProjectRegistryInterface, 'Missing runtime registries.');
    [$moduleCount, $projectCount] = match ($scenario) {
        'symfony-only' => [0, 0],
        'modules-only' => [2, 0],
        'project-only' => [0, 1],
        'single-project' => [2, 1],
        'multi-project' => [3, 2],
        'hybrid' => [2, 2],
    };
    consumerAssert(count($modules->active()) === $moduleCount && count($projects->all()) === $projectCount, 'Unexpected active architecture.');
    $requests = match ($scenario) {
        'symfony-only' => [['http://localhost/', 'standard-symfony', 200]],
        'modules-only' => [['http://localhost/inventory', 'inventory-foundation', 200], ['http://localhost/archived', null, 404]],
        'project-only' => [['http://localhost/portal', 'project-without-modules', 200]],
        'single-project' => [['http://localhost/portal', 'portal', 200], ['http://localhost/profile', 'profile', 200], ['http://localhost/dashboard', 'dashboard', 200]],
        'multi-project' => [['https://shop.example.test/catalog', '{"project":"storefront"}', 200], ['https://admin.example.test/catalog', null, 404]],
        'hybrid' => [['https://public.hybrid.test/health', 'ok', 200], ['https://public.hybrid.test/common', 'common', 200], ['https://admin.hybrid.test/backoffice', 'backoffice', 200], ['https://public.hybrid.test/backoffice', null, 404]],
    };
    foreach ($requests as [$url, $content, $status]) {
        $request = Request::create($url);
        $response = $kernel->handle($request);
        consumerAssert($response->getStatusCode() === $status, 'Unexpected response status for '.$url);
        if (null !== $content) {
            consumerAssert($response->getContent() === $content, 'Unexpected response body for '.$url);
        }
        $kernel->terminate($request, $response);
    }
    $console = new Application($kernel);
    $about = new CommandTester($console->find('sillbeam:about'));
    consumerAssert(0 === $about->execute([]) && str_contains($about->getDisplay(), 'Sillbeam'), 'CLI about failed.');
    $architecture = new CommandTester($console->find('sillbeam:architecture'));
    consumerAssert(0 === $architecture->execute(['--format' => 'json']), 'CLI architecture failed.');
    $data = json_decode($architecture->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
    consumerAssert(count($data['projects']) === $projectCount, 'CLI project catalog differs from runtime.');
    consumerAssert(1 === $data['schema_version'] && 'valid' === $data['status'], 'CLI report schema or status is invalid.');
    $graph = new CommandTester($console->find('sillbeam:graph'));
    consumerAssert(0 === $graph->execute(['--format' => 'mermaid']) && str_starts_with($graph->getDisplay(), 'flowchart LR'), 'CLI Mermaid export failed.');
    foreach ($data['modules'] as $module) {
        $inspect = new CommandTester($console->find('sillbeam:inspect:module'));
        consumerAssert(0 === $inspect->execute(['module' => $module['class']]), 'CLI module inspection failed.');
        consumerAssert(str_contains($inspect->getDisplay(), 'Module: '.$module['id']), 'Inspection identity differs from architecture report.');
    }
    $doctor = new CommandTester($console->find('sillbeam:doctor'));
    $doctor->execute(['--format' => 'json']);
    $doctorData = json_decode($doctor->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
    consumerAssert(1 === $doctorData['schema_version'], 'CLI Doctor JSON schema failed.');
    if ('1' === $withDoctrine) {
        $manager = $container->get('doctrine.orm.entity_manager');
        consumerAssert($manager instanceof EntityManagerInterface, 'Missing EntityManager.');
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        // Native and Sillbeam entities share the same manager and support persistence.
        consumerAssert(count($metadata) === ('symfony-only' === $scenario ? 1 : 2), 'Unexpected native/module/project mappings.');
        (new SchemaTool($manager))->createSchema($metadata);
        $records = [];
        foreach ($metadata as $mapping) {
            $class = $mapping->getName();
            $record = new $class();
            $manager->persist($record);
            $records[] = $record;
        }
        $manager->flush();
        $identifiers = array_map(static fn (object $record): array => [$record::class, $manager->getClassMetadata($record::class)->getIdentifierValues($record)], $records);
        $manager->clear();
        foreach ($identifiers as [$class, $id]) {
            consumerAssert(null !== $manager->find($class, $id), 'Consumer entity persistence failed: '.$class);
        }
    }
    printf("PHP %s / Symfony %s / %s / %s\n", \PHP_VERSION, InstalledVersions::getPrettyVersion('symfony/framework-bundle'), $scenario, get_parent_class($kernel));
} finally {
    $kernel->shutdown();
}

function consumerAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
