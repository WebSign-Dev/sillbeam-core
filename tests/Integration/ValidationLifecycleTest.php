<?php

declare(strict_types=1);

namespace Sillbeam\Core\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sillbeam\Core\Tests\Support\SourceWorkspace;

final class ValidationLifecycleTest extends TestCase
{
    private SourceWorkspace $source;
    private string $kernel;

    protected function setUp(): void
    {
        $this->source = new SourceWorkspace();
        $this->kernel = $this->source->php('src/Kernel.php', 'final class Kernel extends \\Symfony\\Component\\HttpKernel\\Kernel { use \\Symfony\\Bundle\\FrameworkBundle\\Kernel\\MicroKernelTrait; public function getProjectDir(): string { return \\dirname(__DIR__); } }');
        $this->source->write('config/bundles.php', '<?php return [\\Symfony\\Bundle\\FrameworkBundle\\FrameworkBundle::class => ["all" => true], \\Sillbeam\\Core\\SillbeamCoreBundle::class => ["all" => true]];');
        $this->source->write('config/packages/framework.yaml', 'framework: { secret: test, test: true, router: { utf8: true } }');
        $this->source->write('config/services.yaml', 'services: {}');
        $this->source->write('config/routes.yaml', "sillbeam: {resource: ., type: sillbeam}\nnative_status: {path: /status}\n");
        $this->source->php('src/Module/Hello/HelloModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class HelloModule {}');
        $this->source->php('src/Module/Hello/Greeting.php', 'final class Greeting { public function text(): string { return "hello"; } }');
        $this->source->php('src/Module/Hello/Controller/HelloController.php', 'final class HelloController { #[\\Symfony\\Component\\Routing\\Attribute\\Route("/hello", name: "hello")] public function hello(\\'.$this->source->namespace.'\\Module\\Hello\\Greeting $greeting): \\Symfony\\Component\\HttpFoundation\\Response { return new \\Symfony\\Component\\HttpFoundation\\Response($greeting->text()); } }');
        $this->source->write('run.php', <<<'PHP'
<?php
$loader = require $argv[1];
$loader->addPsr4($argv[2].'\\', __DIR__.'/src');
if (isset($argv[6]) && '' !== $argv[6]) { $_SERVER['APP_RUNTIME_MODE'] = $argv[6]; }
$debug = 'dev' === $argv[4];
$kernel = new $argv[3]($argv[4], $debug);
try {
    if (str_starts_with($argv[5], 'http')) {
        $response = $kernel->handle(\Symfony\Component\HttpFoundation\Request::create('/hello'));
        $result = ['status' => $response->getStatusCode(), 'body' => $response->getContent()];
        if ('http-probe' === $argv[5]) { $result['analyzer_loaded'] = class_exists(\Sillbeam\Core\Architecture\ArchitectureAnalyzer::class, false); $result['parser_loaded'] = class_exists(\PhpParser\ParserFactory::class, false); }
        echo json_encode($result, JSON_THROW_ON_ERROR);
    } elseif ('boot' === $argv[5]) {
        $kernel->boot();
        $container = $kernel->getContainer();
        $registry = \Sillbeam\Core\Module\ModuleRegistryInterface::class;
        echo json_encode(['modules' => $container->has($registry) ? count($container->get($registry)->active()) : null], JSON_THROW_ON_ERROR);
    } else {
        // Use the native Symfony console for maintenance and compiled diagnostics.
        $fresh = 'architecture-fresh' === $argv[5];
        $app = $fresh ? new \Sillbeam\Core\Console\Application($kernel) : new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
        $app->setAutoExit(false);
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $command = $fresh ? 'sillbeam:architecture' : $argv[5];
        $arguments = ['command' => $command];
        if ('sillbeam:architecture' === $command) { $arguments['--format'] = 'json'; }
        $code = $app->run(new \Symfony\Component\Console\Input\ArrayInput($arguments), $output);
        echo json_encode(['exit' => $code, 'output' => $output->fetch()], JSON_THROW_ON_ERROR);
    }
} catch (Throwable $error) {
    $result = ['exception' => $error::class, 'message' => $error->getMessage(), 'status' => $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $error->getStatusCode() : 500];
    if ('http-probe' === $argv[5]) { $result['analyzer_loaded'] = class_exists(\Sillbeam\Core\Architecture\ArchitectureAnalyzer::class, false); $result['parser_loaded'] = class_exists(\PhpParser\ParserFactory::class, false); }
    echo json_encode($result, JSON_THROW_ON_ERROR);
} finally { $kernel->shutdown(); }
PHP);
    }

    protected function tearDown(): void
    {
        $this->source->remove();
    }

    public function testColdProductionHttpDoesNotAnalyzeSourcesOrDiscloseFindings(): void
    {
        $this->invalidSource();
        $result = $this->runProcess('prod', 'http');
        self::assertSame(503, $result['status']);
        self::assertSame('Service temporarily unavailable.', $result['message']);
        self::assertStringNotContainsString('Missing', $result['message']);
        self::assertStringNotContainsString($this->source->directory, $result['message']);
    }

    #[DataProvider('invalidArchitectures')]
    public function testNativeConsoleAndCacheClearWorkWithoutAnInvalidGraph(string $scenario, string $rule, int $exit): void
    {
        if ('dependency' === $scenario) {
            $this->invalidSource();
        } elseif ('cycle' === $scenario) {
            $namespace = $this->source->namespace;
            $this->source->php('src/Module/A/AModule.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(dependencies: [\\'.$namespace.'\Module\B\BModule::class])] final class AModule {}');
            $this->source->php('src/Module/B/BModule.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(dependencies: [\\'.$namespace.'\Module\A\AModule::class])] final class BModule {}');
        } elseif ('metadata' === $scenario) {
            $this->source->php('src/Module/Broken/BrokenModule.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(id: "%invalid-id%")] final class BrokenModule {}');
        } else {
            $this->source->write('src/Module/Broken/Broken.php', '<?php class {');
        }
        foreach (['about', 'cache:clear', 'cache:warmup'] as $command) {
            $result = $this->runProcess('dev', $command);
            self::assertSame(0, $result['exit'] ?? null, json_encode($result, \JSON_THROW_ON_ERROR));
        }
        self::assertSame(['modules' => null], $this->runProcess('dev', 'boot'));
        $routes = $this->runProcess('dev', 'debug:router');
        self::assertSame(0, $routes['exit']);
        self::assertStringContainsString('native_status', $routes['output']);
        self::assertStringNotContainsString('/hello', $routes['output']);
        $report = $this->runProcess('dev', 'sillbeam:architecture');
        self::assertSame($exit, $report['exit']);
        self::assertStringContainsString($rule, $report['output']);
        $data = json_decode($report['output'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertFalse($data['valid']);
        if ('metadata' === $scenario) {
            self::assertStringContainsString('"%invalid-id%"', $data['findings'][0]['message']);
        }
    }

    public function testDebugCacheRebuildsAfterRepairingAnUnparseableSource(): void
    {
        $this->source->write('src/Module/Broken/Broken.php', '<?php class {');
        self::assertSame(['modules' => null], $this->runProcess('dev', 'boot'));
        // Symfony debug resources compare modification times at second granularity.
        sleep(1);
        $this->source->write('src/Module/Broken/Broken.php', '<?php // Repaired.');
        self::assertSame(['modules' => 1], $this->runProcess('dev', 'boot'));
        self::assertSame(['status' => 200, 'body' => 'hello'], $this->runProcess('dev', 'http'));
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function invalidArchitectures(): iterable
    {
        yield 'missing dependency' => ['dependency', 'dependency.unknown', 1];
        yield 'cycle' => ['cycle', 'dependency.cycle', 1];
        yield 'invalid descriptor metadata' => ['metadata', 'discovery.descriptor_metadata', 2];
        yield 'syntax error' => ['syntax', 'source.parse', 2];
    }

    #[DataProvider('environments')]
    public function testValidColdAndWarmCliWarmupAndHttpKeepRuntimeIntegrations(string $environment): void
    {
        self::assertSame(['modules' => 1], $this->runProcess($environment, 'boot'));
        $warmup = $this->runProcess($environment, 'cache:warmup');
        self::assertSame(0, $warmup['exit'], $warmup['output']);
        self::assertSame(['status' => 200, 'body' => 'hello'], $this->runProcess($environment, 'http'));
        self::assertSame(['status' => 200, 'body' => 'hello'], $this->runProcess($environment, 'http'));
    }

    public function testNativeWebWorkerModeCannotCompileAProductionContainer(): void
    {
        $this->invalidSource();
        $result = $this->runProcess('prod', 'boot', 'web=1&worker=1');
        self::assertSame(503, $result['status']);
        self::assertSame('Service temporarily unavailable.', $result['message']);
    }

    public function testCacheClearRebuildsAValidProductionCacheWithoutInvalidIntegration(): void
    {
        self::assertSame(['modules' => 1], $this->runProcess('prod', 'boot'));
        $this->invalidSource();
        $report = $this->runProcess('prod', 'architecture-fresh');
        self::assertSame(1, $report['exit']);
        self::assertStringContainsString('dependency.unknown', $report['output']);
        // Symfony skips rebuilding a cache created in the current second.
        sleep(1);
        $result = $this->runProcess('prod', 'cache:clear', 'web=1');
        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame(['modules' => null], $this->runProcess('prod', 'boot'));
        self::assertSame(404, $this->runProcess('prod', 'http')['status']);
    }

    public function testProductionHttpNeverLoadsTheSourceAnalyzerWithColdOrWarmCache(): void
    {
        $cold = $this->runProcess('prod', 'http-probe');
        self::assertSame(503, $cold['status']);
        self::assertFalse($cold['analyzer_loaded']);
        self::assertFalse($cold['parser_loaded']);
        self::assertSame(['modules' => 1], $this->runProcess('prod', 'boot'));
        $this->invalidSource();
        $warm = $this->runProcess('prod', 'http-probe');
        self::assertSame(200, $warm['status']);
        self::assertFalse($warm['analyzer_loaded']);
        self::assertFalse($warm['parser_loaded']);
    }

    /** @return iterable<string, array{string}> */
    public static function environments(): iterable
    {
        yield 'development' => ['dev'];
        yield 'production' => ['prod'];
    }

    private function invalidSource(): void
    {
        $this->source->php('src/Module/Broken/BrokenModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule(dependencies: [\\Missing\\Module::class])] final class BrokenModule {}');
    }

    /**
     * Each invocation observes current source/cache state in a new subprocess.
     *
     * @phpstan-impure
     *
     * @return array<string, mixed>
     */
    private function runProcess(string $environment, string $operation, string $runtimeMode = ''): array
    {
        $stdout = $this->source->directory.'/stdout.log';
        $stderr = $this->source->directory.'/stderr.log';
        $process = proc_open([\PHP_BINARY, $this->source->directory.'/run.php', \dirname(__DIR__, 2).'/vendor/autoload.php', $this->source->namespace, $this->kernel, $environment, $operation, $runtimeMode], [\STDIN, ['file', $stdout, 'w'], ['file', $stderr, 'w']], $pipes);
        self::assertIsResource($process);
        self::assertSame(0, proc_close($process), (string) file_get_contents($stderr));

        return json_decode((string) file_get_contents($stdout), true, flags: \JSON_THROW_ON_ERROR);
    }
}
