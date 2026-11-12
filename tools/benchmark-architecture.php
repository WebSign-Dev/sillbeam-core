<?php

declare(strict_types=1);

// Development-only, paired benchmark. Each measured build/boot/batch gets a fresh PHP process.
require dirname(__DIR__).'/vendor/autoload.php';

use Sillbeam\Core\Architecture\ArchitectureAnalyzer;
use Sillbeam\Core\Architecture\ArchitectureCompiler;
use Sillbeam\Core\Architecture\ArchitectureDiscovery;
use Sillbeam\Core\Architecture\CodeDependencyAnalyzer;
use Sillbeam\Core\Architecture\DiscoveryOptions;
use Sillbeam\Core\Architecture\SourceInspector;
use Sillbeam\Core\Console\KernelArchitectureReporter;
use Sillbeam\Core\Module\ModuleRegistryInterface;
use Sillbeam\Core\Tests\Support\SourceWorkspace;
use Sillbeam\Core\Tests\Support\TestApplicationKernel;

if ('--phase-worker' === ($argv[1] ?? null)) {
    $count = (int) $argv[2];
    $environment = $argv[3];
    $source = new SourceWorkspace();
    $kernel = new TestApplicationKernel($source, false, $environment, 'dev' === $environment);
    $measure = static function (callable $operation): array {
        $start = hrtime(true);
        $result = $operation();

        return [$result, round((hrtime(true) - $start) / 1e6, 3)];
    };

    try {
        for ($module = 0; $module < $count; ++$module) {
            $area = 'src/Module/M'.$module;
            $previous = $source->namespace.'\\Module\\M'.($module - 1);
            $dependencies = 0 === $module ? '[]' : '[\\'.$previous.'\\Descriptor::class]';
            $source->php($area.'/Descriptor.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(id: "m'.$module.'", dependencies: '.$dependencies.')] final class Descriptor {}');
            for ($class = 0; $class < 20; ++$class) {
                $body = 0 === $module ? '' : 'public function __construct(public \\'.$previous.'\\Service'.$class.' $previous) {}';
                $source->php($area.'/Service'.$class.'.php', 'final class Service'.$class.' { '.$body.' }');
            }
            $source->php($area.'/Controller/Endpoint.php', 'final class Endpoint { #[\Symfony\Component\Routing\Attribute\Route("/m'.$module.'", name: "m'.$module.'")] public function run(): \Symfony\Component\HttpFoundation\Response { return new \Symfony\Component\HttpFoundation\Response("ok"); } }');
        }
        $source->write('config/packages/framework.yaml', 'framework: { secret: benchmark, router: { utf8: true } }');
        $source->write('config/routes.yaml', 'sillbeam: { resource: ., type: sillbeam }');
        $source->write('config/services.yaml', "services:\n    _defaults: { autowire: true, autoconfigure: true }\n    '".$source->namespace."\\':\n        resource: '../src/'\n        exclude: '../src/{Module,Project}'\n");
        $options = new DiscoveryOptions($source->directory.'/src', $source->namespace.'\\');
        $inspector = new SourceInspector();
        [$files, $parse] = $measure(static fn () => $inspector->files($options));
        [, $indexWarm] = $measure(static fn () => $inspector->files($options));
        [$discovery, $discoveryTime] = $measure(static fn () => (new ArchitectureDiscovery($inspector))->discover($options));
        [$architecture, $compile] = $measure(static fn () => (new ArchitectureCompiler())->compile($discovery, []));
        [$references, $analysis] = $measure(static fn () => (new CodeDependencyAnalyzer($inspector))->analyze($discovery, $architecture, $options));
        [, $discoveryWarm] = $measure(static fn () => (new ArchitectureDiscovery($inspector))->discover($options));
        [, $analysisWarm] = $measure(static fn () => (new CodeDependencyAnalyzer($inspector))->analyze($discovery, $architecture, $options));
        [, $containerCold] = $measure(static fn () => $kernel->boot());
        [, $routesCold] = $measure(static fn () => $kernel->getContainer()->get('router')->getRouteCollection());
        $kernel->shutdown();
        [, $containerWarm] = $measure(static fn () => $kernel->boot());
        [, $routesWarm] = $measure(static fn () => $kernel->getContainer()->get('router')->getRouteCollection());
        echo json_encode([
            'php' => \PHP_VERSION, 'modules' => $count, 'environment' => $environment,
            'files' => count($files), 'references' => array_sum(array_map('count', $references)),
            'ms' => ['index_cold' => $parse, 'index_warm' => $indexWarm, 'discovery_after_index' => $discoveryTime, 'discovery_warm' => $discoveryWarm, 'compile' => $compile, 'analysis' => $analysis, 'analysis_warm' => $analysisWarm, 'container_cold' => $containerCold, 'container_warm' => $containerWarm, 'routes_cold' => $routesCold, 'routes_warm' => $routesWarm],
            'peak_mib' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)."\n";
    } finally {
        $kernel->shutdown();
        $source->remove();
    }

    exit(0);
}

if ('--sample' === ($argv[1] ?? null)) {
    [, , $directory, $namespace, $environment, $operation, $requests, $count] = $argv;
    $_SERVER['SHELL_VERBOSITY'] = '-1';
    $loader = require dirname(__DIR__).'/vendor/autoload.php';
    $loader->addPsr4($namespace.'\\', $directory.'/src');
    $kernelClass = $namespace.'\\Kernel';
    $newKernel = static fn () => new $kernelClass($environment, 'dev' === $environment);
    $times = [];
    $kernel = $newKernel();
    try {
        memory_reset_peak_usage();
        if (in_array($operation, ['cache:warmup', 'cache:clear'], true)) {
            $application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
            $application->setAutoExit(false);
            $output = new Symfony\Component\Console\Output\BufferedOutput();
            $start = hrtime(true);
            $code = $application->run(new Symfony\Component\Console\Input\ArrayInput(['command' => $operation]), $output);
            $times[] = (hrtime(true) - $start) / 1e6;
            if (0 !== $code) {
                throw new RuntimeException($output->fetch());
            }
        } elseif ('architecture' === $operation) {
            $start = hrtime(true);
            $report = (new KernelArchitectureReporter())->report($kernel);
            $times[] = (hrtime(true) - $start) / 1e6;
            if (0 !== $report->exitCode() || (int) $count !== count($report->data['modules'])) {
                throw new RuntimeException('Explicit architecture report differs from fixture.');
            }
        } elseif ('boot' === $operation) {
            $start = hrtime(true);
            $kernel->boot();
            $times[] = (hrtime(true) - $start) / 1e6;
        } elseif ('http' === $operation) {
            for ($request = 0; $request <= (int) $requests; ++$request) {
                $kernel = $newKernel();
                $input = Symfony\Component\HttpFoundation\Request::create('/m'.($count - 1));
                $start = hrtime(true);
                $response = $kernel->handle($input);
                $times[] = (hrtime(true) - $start) / 1e6;
                if (200 !== $response->getStatusCode() || 'ok:'.(20 * $count) !== $response->getContent()) {
                    throw new RuntimeException('Benchmark endpoint behavior differs: '.$response->getStatusCode().' '.$response->getContent());
                }
                $kernel->terminate($input, $response);
                if ($request < (int) $requests) {
                    $kernel->shutdown();
                }
            }
        }
        $sample = [
            'ms' => $times, 'peak_mib' => memory_get_peak_usage(true) / 1024 / 1024,
            'analyzer_loaded' => class_exists(ArchitectureAnalyzer::class, false),
            'parser_loaded' => class_exists(PhpParser\ParserFactory::class, false),
            'sillbeam_bundle_loaded' => class_exists(Sillbeam\Core\SillbeamCoreBundle::class, false),
        ];
        if ('architecture' !== $operation) {
            $container = $kernel->getContainer();
            $sample['container_file'] = (new ReflectionObject($container))->getFileName();
            $sample['service_ids'] = count($container->getServiceIds());
            $sample['registry_modules'] = $container->has(ModuleRegistryInterface::class) ? count($container->get(ModuleRegistryInterface::class)->all()) : 0;
            $sample['routes'] = count($container->get('router')->getRouteCollection());
        }
        echo json_encode($sample, \JSON_THROW_ON_ERROR)."\n";
    } finally {
        $kernel->shutdown();
    }
    exit(0);
}

if ('--phases' === ($argv[1] ?? null)) {
    foreach ([10, 50, 100] as $modules) {
        foreach (['dev', 'prod'] as $environment) {
            echo json_encode(benchmarkProcess(['--phase-worker', (string) $modules, $environment]), \JSON_THROW_ON_ERROR)."\n";
        }
    }
    exit(0);
}
if (isset($argv[1]) && '--smoke' !== $argv[1]) {
    throw new InvalidArgumentException('Usage: php tools/benchmark-architecture.php [--smoke|--phases]');
}
$smoke = '--smoke' === ($argv[1] ?? null);
$repeats = $smoke ? 1 : 5;
$requests = $smoke ? 5 : 50;
echo json_encode([
    'type' => 'metadata', 'schema_version' => 3, 'date_utc' => gmdate(\DATE_ATOM),
    'tool_sha256' => hash_file('sha256', __FILE__),
    'composer_lock_sha256' => hash_file('sha256', dirname(__DIR__).'/composer.lock'),
    'http_foundation' => Composer\InstalledVersions::getPrettyVersion('symfony/http-foundation'),
    'php' => \PHP_VERSION, 'symfony' => Composer\InstalledVersions::getPrettyVersion('symfony/framework-bundle'),
    'os' => php_uname(), 'sapi' => \PHP_SAPI, 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'),
    'memory_limit' => ini_get('memory_limit'), 'repeats' => $repeats, 'http_requests_per_repeat' => $requests,
    'percentile' => 'nearest-rank', 'http_transport' => 'in-process Kernel::handle, fresh kernel per request; excludes network and termination',
    'cold' => 'fresh process and empty Symfony cache; OS cache not flushed',
    'build_rebuild' => 'fresh process, Symfony cache removed after priming, source and OS caches not flushed',
    'cache_clear' => 'existing cache aged past the current second, default cache:clear with warmup',
    'cache_warmup_cold' => 'cache:warmup with empty Symfony cache after priming',
    'cache_warmup_warm' => 'cache:warmup with unchanged existing cache',
    'architecture' => 'fresh pre-compilation architecture report, excludes CLI rendering',
    'structure' => 'fixture services exclude controllers; compiled service IDs include framework and aliases',
    'memory' => 'maximum PHP allocated peak per operation across repeats; not RSS',
], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)."\n";
foreach ($smoke ? [2] : [10, 50, 100] as $count) {
    foreach ($smoke ? ['prod'] : ['dev', 'prod'] as $environment) {
        $results = [];
        foreach (['symfony', 'sillbeam'] as $variant) {
            $results[$variant] = ['type' => 'comparison', 'variant' => $variant, 'modules' => $count, 'environment' => $environment, 'source_php_files' => 22 * $count + 1, 'services' => 20 * $count, 'routes' => $count, 'response' => 'ok:'.(20 * $count), 'samples_ms' => [], 'peak_mib' => []];
        }
        // Alternate pair order between repetitions to reduce systematic order bias.
        for ($repeat = 0; $repeat < $repeats; ++$repeat) {
            foreach (0 === $repeat % 2 ? ['symfony', 'sillbeam'] : ['sillbeam', 'symfony'] as $variant) {
                $source = benchmarkFixture($count, $variant);
                try {
                    $operations = [
                        'build_cold' => 'boot', 'boot_warm' => 'boot', 'cache_warmup_warm' => 'cache:warmup',
                        'http_warm' => 'http', 'cache_clear' => 'cache:clear', 'build_rebuild' => 'boot',
                        'cache_warmup_cold' => 'cache:warmup',
                    ];
                    if ('sillbeam' === $variant) {
                        $operations['architecture'] = 'architecture';
                    }
                    foreach ($operations as $metric => $operation) {
                        if (in_array($metric, ['build_rebuild', 'cache_warmup_cold'], true)) {
                            (new Symfony\Component\Filesystem\Filesystem())->remove($source->directory.'/var/cache');
                        }
                        if ('cache_clear' === $metric) {
                            // Symfony skips rebuilding containers generated during the current second.
                            while (filemtime($containerFile) >= time()) {
                                usleep(20_000);
                            }
                        }
                        $sample = benchmarkProcess(['--sample', $source->directory, $source->namespace, $environment, $operation, (string) $requests, (string) $count]);
                        if ('architecture' !== $metric) {
                            $containerFile = $sample['container_file'];
                            if ($count !== $sample['routes'] || ('sillbeam' === $variant ? $count : 0) !== $sample['registry_modules']) {
                                throw new RuntimeException('Benchmark route or module count differs.');
                            }
                        }
                        $expectsAnalysis = 'sillbeam' === $variant && in_array($metric, ['build_cold', 'cache_clear', 'build_rebuild', 'cache_warmup_cold', 'architecture'], true);
                        if ($expectsAnalysis !== $sample['analyzer_loaded'] || $expectsAnalysis !== $sample['parser_loaded']) {
                            throw new RuntimeException('Unexpected source analysis state for '.$variant.' '.$environment.' '.$metric);
                        }
                        $results[$variant]['analysis_loaded'][$metric] = $sample['analyzer_loaded'];
                        if ('build_cold' === $metric) {
                            $results[$variant]['compiled_service_ids'] = $sample['service_ids'];
                            $results[$variant]['registry_modules'] = $sample['registry_modules'];
                            $results[$variant]['cache_bytes_after_build'] = array_sum(array_map(static fn (SplFileInfo $file): int => $file->getSize(), iterator_to_array(Symfony\Component\Finder\Finder::create()->files()->in($source->directory.'/var/cache'))));
                        }
                        $results[$variant]['peak_mib'][$metric] = max($results[$variant]['peak_mib'][$metric] ?? 0, $sample['peak_mib']);
                        if ('http' === $operation) {
                            $results[$variant]['samples_ms']['http_first'][] = array_shift($sample['ms']);
                            $results[$variant]['http_analyzer_loaded'] = $sample['analyzer_loaded'];
                            $results[$variant]['http_parser_loaded'] = $sample['parser_loaded'];
                            $results[$variant]['http_sillbeam_bundle_loaded'] = $sample['sillbeam_bundle_loaded'];
                        }
                        $results[$variant]['samples_ms'][$metric] = [...($results[$variant]['samples_ms'][$metric] ?? []), ...$sample['ms']];
                    }
                } finally {
                    $source->remove();
                }
            }
        }
        foreach ($results as $result) {
            foreach ($result['samples_ms'] as $metric => $samples) {
                sort($samples, \SORT_NUMERIC);
                $result['ms'][$metric] = ['n' => count($samples), 'min' => min($samples), 'p50' => $samples[(int) ceil(count($samples) * .5) - 1], 'p95' => $samples[(int) ceil(count($samples) * .95) - 1], 'max' => max($samples)];
            }
            echo json_encode($result, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)."\n";
        }
    }
}

/** @param list<string> $arguments
 * @return array<string, mixed>
 */
function benchmarkProcess(array $arguments): array
{
    $process = proc_open([\PHP_BINARY, __FILE__, ...$arguments], [\STDIN, ['pipe', 'w'], \STDERR], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start benchmark subprocess.');
    }
    $json = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (0 !== proc_close($process)) {
        throw new RuntimeException('Benchmark subprocess failed: '.implode(' ', $arguments));
    }

    return json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
}

function benchmarkFixture(int $count, string $variant): SourceWorkspace
{
    $source = new SourceWorkspace();
    $source->php('src/Kernel.php', 'final class Kernel extends \\Symfony\\Component\\HttpKernel\\Kernel { use \\Symfony\\Bundle\\FrameworkBundle\\Kernel\\MicroKernelTrait; public function getProjectDir(): string { return \\dirname(__DIR__); } }');
    $source->write('config/bundles.php', '<?php return [\\Symfony\\Bundle\\FrameworkBundle\\FrameworkBundle::class => ["all" => true]'.('sillbeam' === $variant ? ', \\Sillbeam\\Core\\SillbeamCoreBundle::class => ["all" => true]' : '').'];');
    $source->write('config/packages/framework.yaml', 'framework: {secret: benchmark, router: {utf8: true}}');
    $routes = 'sillbeam' === $variant ? "sillbeam: {resource: ., type: sillbeam}\n" : '';
    for ($module = 0; $module < $count; ++$module) {
        $area = 'src/Module/M'.$module;
        $previous = $source->namespace.'\\Module\\M'.($module - 1);
        $dependencies = 0 === $module ? '[]' : '[\\'.$previous.'\\Descriptor::class]';
        $source->php($area.'/Descriptor.php', ('sillbeam' === $variant ? '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule(id: "m'.$module.'", dependencies: '.$dependencies.')] ' : '').'final class Descriptor {}');
        $arguments = [];
        $values = [];
        for ($class = 0; $class < 20; ++$class) {
            $body = 0 === $module ? 'public function value(): int { return 1; }' : 'public function __construct(private \\'.$previous.'\\Service'.$class.' $previous) {} public function value(): int { return 1 + $this->previous->value(); }';
            $source->php($area.'/Service'.$class.'.php', 'final class Service'.$class.' { '.$body.' }');
            $arguments[] = 'private \\'.$source->namespace.'\\Module\\M'.$module.'\\Service'.$class.' $s'.$class;
            $values[] = '$this->s'.$class.'->value()';
        }
        $source->php($area.'/Controller/Endpoint.php', 'final class Endpoint { public function __construct('.implode(', ', $arguments).') {} #[\\Symfony\\Component\\Routing\\Attribute\\Route("/m'.$module.'", name: "m'.$module.'")] public function run(): \\Symfony\\Component\\HttpFoundation\\Response { return new \\Symfony\\Component\\HttpFoundation\\Response("ok:".array_sum(['.implode(', ', $values).'])); } }');
        if ('symfony' === $variant) {
            $routes .= 'm'.$module.': {resource: ../src/Module/M'.$module.'/Controller/, type: attribute}'."\n";
        }
    }
    $source->write('config/routes.yaml', $routes);
    $source->write('config/services.yaml', 'sillbeam' === $variant ? 'services: {}' : "services:\n    _defaults: {autowire: true, autoconfigure: true}\n    '".$source->namespace."\\Module\\':\n        resource: '../src/Module/'\n        exclude: '../src/Module/*/Descriptor.php'\n");

    return $source;
}
