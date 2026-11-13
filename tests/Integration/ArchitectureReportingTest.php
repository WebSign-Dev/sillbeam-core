<?php

declare(strict_types=1);

namespace Sillbeam\Core\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sillbeam\Core\Console\Application;
use Sillbeam\Core\Tests\Support\SourceWorkspace;
use Sillbeam\Core\Tests\Support\TestApplicationKernel;
use Symfony\Component\Console\Tester\ApplicationTester;

final class ArchitectureReportingTest extends TestCase
{
    private SourceWorkspace $source;

    protected function setUp(): void
    {
        $this->source = new SourceWorkspace();
        $this->source->write('config/packages/framework.yaml', 'framework: { secret: test, test: true }');
        $this->source->write('config/services.yaml', 'services: {}');
    }

    protected function tearDown(): void
    {
        $this->source->remove();
    }

    public function testCompiledReportRetainsLiteralParametersAndPresentation(): void
    {
        $this->module('A');
        $kernel = new TestApplicationKernel($this->source);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(\Psr\Container\ContainerInterface::class, $container);
            $report = $container->get(\Sillbeam\Core\Architecture\ArchitectureReport::class);
            self::assertInstanceOf(\Sillbeam\Core\Architecture\ArchitectureReport::class, $report);
            self::assertSame('a', $report->data['modules'][0]['id']);
            self::assertNotEmpty($report->presentation);
            $payload = new \Sillbeam\Core\Architecture\ArchitectureReport(
                ['literal' => "%kernel.environment% %env(NOT_SET)% \xFF"],
                ['localized' => 'Grüße'],
            );
            $restored = \Sillbeam\Core\DependencyInjection\CompiledArchitectureReport::restore(
                base64_encode(serialize([$payload->data, $payload->presentation])),
            );
            self::assertSame($payload->data, $restored->data);
            self::assertSame($payload->presentation, $restored->presentation);
        } finally {
            $kernel->shutdown();
        }
    }

    public function testJsonReportsValidCatalogWithoutBootingABrokenServiceContainer(): void
    {
        $this->module('A');
        $this->source->write('config/services.yaml', "services:\n    broken:\n        class: stdClass\n        public: true\n        arguments: ['@nonexistent_service']\n");
        $kernel = new TestApplicationKernel($this->source);
        try {
            $kernel->boot();
            self::fail('Fixture must have a broken Symfony container.');
        } catch (\Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException) {
        } finally {
            $kernel->shutdown();
        }
        [$code, $output, $stderr] = $this->runReport('json');
        self::assertSame(0, $code);
        self::assertSame('', $stderr);
        self::assertStringNotContainsString("\033", $output);
        $data = json_decode($output, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(1, $data['schema_version']);
        $this->assertSchemaKeys($data);
        self::assertSame('valid', $data['status']);
        self::assertTrue($data['valid']);
        self::assertTrue($data['complete']);
        self::assertSame([], $data['findings']);
        self::assertSame('a', $data['modules'][0]['id']);
        self::assertTrue($data['modules'][0]['active']);
        self::assertSame(['application'], $data['modules'][0]['activation_reasons']);
    }

    public function testBothFormatsReportAllViolationsAndUseExitOne(): void
    {
        $this->module('A', '[\\Missing\\One::class]');
        $this->module('B', '[\\Missing\\Two::class]');
        [$code, $json] = $this->runReport('json');
        self::assertSame(1, $code);
        $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('violations', $data['status']);
        self::assertCount(2, $data['findings']);
        $this->assertSchemaKeys($data);
        self::assertCount(2, $data['modules']);
        self::assertNull($data['modules'][0]['active']);
        self::assertSame('unknown', $data['modules'][0]['inactive_reason']);
        [$code, $text] = $this->runReport('text');
        self::assertSame(1, $code);
        self::assertStringContainsString('2 findings · 2 diagnoses', $text);
        self::assertStringContainsString('Missing\\One', $text);
        self::assertStringContainsString('Missing\\Two', $text);
    }

    public function testNativeImportsParametersEnvironmentAndPhpConfigurationAreUsed(): void
    {
        $this->module('A');
        $this->source->write('config/shared.yaml', "parameters:\n    report_off: false\nsillbeam:\n    modules:\n        a: {enabled: '%report_off%'}\n");
        $this->source->write('config/packages/sillbeam.yaml', "imports:\n    - {resource: ../shared.yaml}\n");
        $this->source->write('config/packages/test/sillbeam.php', '<?php use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator; return static function (ContainerConfigurator $config): void { echo "unwanted config output"; $config->parameters()->set("report_off", "%env(bool:SILLBEAM_REPORT_ENABLED)%"); };');
        $_ENV['SILLBEAM_REPORT_ENABLED'] = '0';
        try {
            [$code, $output] = $this->runReport('json');
            self::assertSame(0, $code);
            $data = json_decode($output, true, flags: \JSON_THROW_ON_ERROR);
            self::assertFalse($data['modules'][0]['enabled']);
            self::assertSame('disabled', $data['modules'][0]['inactive_reason']);
            self::assertStringNotContainsString('unwanted config output', $output);
        } finally {
            unset($_ENV['SILLBEAM_REPORT_ENABLED']);
        }
    }

    public function testInvalidConfigurationAndFatalAnalysisUseExitTwoAndTheSameJsonSchema(): void
    {
        $this->source->write('config/packages/sillbeam.yaml', 'sillbeam: { nonexistent_setting: true }');
        [$code, $json] = $this->runReport('json');
        self::assertSame(2, $code);
        $invalidConfig = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('unavailable', $invalidConfig['status']);
        self::assertFalse($invalidConfig['complete']);
        self::assertSame('fatal', $invalidConfig['findings'][0]['severity']);
        $this->source->write('config/packages/sillbeam.yaml', 'sillbeam: {}');
        $this->source->write('src/Module/Bad/Bad.php', '<?php class {');
        [$code, $json] = $this->runReport('json');
        self::assertSame(2, $code);
        $fatal = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(array_keys($invalidConfig), array_keys($fatal));
        self::assertSame('source.parse', $fatal['findings'][0]['rule']);
    }

    public function testJsonUsageErrorsStayJsonAndReturnTwo(): void
    {
        [$code, $json, $stderr] = $this->runReport('json', ['--unknown-option' => true]);
        self::assertSame(2, $code);
        self::assertSame('', $stderr);
        self::assertStringNotContainsString("\033", $json);
        self::assertSame('unavailable', json_decode($json, true, flags: \JSON_THROW_ON_ERROR)['status']);
    }

    public function testOtherCommandsStillUseTheNormalSymfonyApplication(): void
    {
        $application = new Application(new TestApplicationKernel($this->source));
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        self::assertSame(0, $tester->run(['command' => 'sillbeam:about']));
        self::assertStringContainsString('Symfony', $tester->getDisplay());
        $application->getKernel()->shutdown();
    }

    public function testFindCanRetrieveArchitectureCommandWithoutBooting(): void
    {
        $this->module('A', '[\\Missing\\One::class]');
        $application = new Application(new TestApplicationKernel($this->source));
        $tester = new \Symfony\Component\Console\Tester\CommandTester($application->find('sillbeam:architecture'));
        self::assertSame(1, $tester->execute(['--format' => 'json']));
        self::assertSame('violations', json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR)['status']);
    }

    public function testLatePrependNamespaceWithoutDoctrineMatchesNormalValidation(): void
    {
        $this->module('A');
        $this->source->write('composer.json', '{}');
        $kernel = $this->prependingKernel(false, false, ['discovery' => ['namespace' => $this->source->namespace.'\\']]);
        $kernel->boot();
        $kernel->shutdown();
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        self::assertSame(0, $tester->run(['command' => 'sillbeam:architecture', '--format' => 'json']));
        self::assertSame('valid', json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR)['status']);
    }

    public function testLateDoctrinePrependChangesAreRejectedLikeNormalValidation(): void
    {
        $this->module('A');
        $kernel = $this->prependingKernel(true, false, ['modules' => ['a' => ['enabled' => false]]]);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        self::assertSame(2, $tester->run(['command' => 'sillbeam:architecture', '--format' => 'json']));
        $data = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('changed after prepend()', $data['findings'][0]['message']);
    }

    public function testEarlyPrependConfigurationIsUsedForCatalogAndProjects(): void
    {
        $this->module('A');
        $this->source->php('src/Project/Shop/ShopProject.php', '#[\\Sillbeam\\Core\\Project\\Attribute\\SillbeamProject] final class ShopProject {}');
        $kernel = $this->prependingKernel(false, true, ['modules' => ['a' => ['enabled' => false]], 'projects' => ['shop' => ['hosts' => ['Shop.Test']]]]);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        self::assertSame(0, $tester->run(['command' => 'sillbeam:architecture', '--format' => 'json']));
        $data = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertFalse($data['modules'][0]['enabled']);
        self::assertSame(['shop.test'], $data['projects'][0]['hosts']);
        $this->assertSchemaKeys($data);
        self::assertSame([], $data['projects'][0]['modules']);
    }

    /** @param array<string, mixed> $configuration */
    private function prependingKernel(bool $doctrine, bool $before, array $configuration): \Symfony\Component\HttpKernel\Kernel
    {
        return new class($this->source, $doctrine, $before, $configuration) extends \Symfony\Component\HttpKernel\Kernel {
            use \Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;

            /** @param array<string, mixed> $configuration */
            public function __construct(private readonly SourceWorkspace $source, private readonly bool $doctrine, private readonly bool $before, private readonly array $configuration)
            {
                parent::__construct('test', true);
            }

            public function getProjectDir(): string
            {
                return $this->source->directory;
            }

            protected function getContainerClass(): string
            {
                return $this->source->namespace.'ReportingContainer';
            }

            public function registerBundles(): iterable
            {
                yield new \Symfony\Bundle\FrameworkBundle\FrameworkBundle();
                if ($this->doctrine) {
                    yield new \Doctrine\Bundle\DoctrineBundle\DoctrineBundle();
                }
                $bundle = new ReportingPrependBundle($this->configuration);
                if ($this->before) {
                    yield $bundle;
                }
                yield new \Sillbeam\Core\SillbeamCoreBundle();
                if (!$this->before) {
                    yield $bundle;
                }
            }
        };
    }

    public function testDeprecationsDoNotInvalidateReportingOrPolluteJson(): void
    {
        $this->module('A');
        $this->source->write('config/packages/deprecation.php', '<?php return static function (\Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator $config): void { trigger_error("legacy config", E_USER_DEPRECATED); };');
        [$code, $output, $stderr] = $this->runReport('json');
        self::assertSame(0, $code);
        self::assertSame('', $stderr);
        self::assertSame('valid', json_decode($output, true, flags: \JSON_THROW_ON_ERROR)['status']);
    }

    public function testArgvGlobalOptionsBeforeCommandAndQuietJsonWork(): void
    {
        $this->module('A');
        $application = new Application(new TestApplicationKernel($this->source));
        $application->setAutoExit(false);
        $input = new \Symfony\Component\Console\Input\ArgvInput(['console', '--env', 'test', '--format', 'json', 'sillbeam:architecture', '--quiet', '--ansi']);
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        self::assertSame(0, $application->run($input, $output));
        $json = $output->fetch();
        self::assertStringNotContainsString("\033", $json);
        self::assertSame('', $json);
        $input = new \Symfony\Component\Console\Input\ArgvInput(['console', '--env', 'test', '--format', 'json', 'sillbeam:architecture', '--ansi']);
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        self::assertSame(0, $application->run($input, $output));
        self::assertSame('valid', json_decode($output->fetch(), true, flags: \JSON_THROW_ON_ERROR)['status']);
    }

    public function testValidTextReportIsCompactWithoutAnUnrequestedModuleCatalog(): void
    {
        $this->module('A');
        [$code, $text, $stderr] = $this->runReport('text');
        self::assertSame(0, $code);
        self::assertSame('', $stderr);
        self::assertStringContainsString('Architecture valid', $text);
        self::assertStringContainsString('1 modules · 0 projects · 0 violations', $text);
        self::assertStringNotContainsString('DIAGNOSIS OVERVIEW', $text);
        self::assertStringNotContainsString('APPLICATION COUPLINGS', $text);
        [, $verbose] = $this->runReport('text', ['--verbose' => true]);
        self::assertStringContainsString('Architecture valid', $verbose);
        self::assertStringNotContainsString($this->source->directory, $verbose);
    }

    /** @param array<string, mixed> $data */
    private function assertSchemaKeys(array $data): void
    {
        $schema = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/docs/reference/architecture-report.schema.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame($schema['required'], array_keys($data));
        foreach (['modules', 'projects', 'findings'] as $field) {
            foreach ($data[$field] as $item) {
                self::assertSame([], array_diff($schema['properties'][$field]['items']['required'], array_keys($item)));
                self::assertSame([], array_diff(array_keys($item), array_keys($schema['properties'][$field]['items']['properties'])));
            }
        }
    }

    public function testMarkdownExportWorksWithoutCompilableContainerForValidAndInvalidArchitecture(): void
    {
        $this->module('A');
        $this->source->php('src/Project/Shop/ShopProject.php', '#[\\Sillbeam\\Core\\Project\\Attribute\\SillbeamProject] final class ShopProject {}');
        $this->source->write('config/services.yaml', "services:\n    broken:\n        class: stdClass\n        public: true\n        arguments: ['@nonexistent_service']\n");
        $kernel = new TestApplicationKernel($this->source);
        try {
            $kernel->boot();
            self::fail('The service container must be uncompilable.');
        } catch (\Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException) {
        } finally {
            $kernel->shutdown();
        }
        foreach ([false, true] as $invalid) {
            if ($invalid) {
                $this->module('B', '[\\Missing\\One::class]');
            }
            $application = new Application(new TestApplicationKernel($this->source));
            $application->setAutoExit(false);
            $tester = new ApplicationTester($application);
            self::assertSame($invalid ? 1 : 0, $tester->run(['command' => 'sillbeam:report']));
            $markdown = (string) file_get_contents($this->source->directory.'/architecture-report.md');
            self::assertStringContainsString('Status: '.($invalid ? 'violations' : 'valid'), $markdown);
            self::assertStringContainsString('Modules checked: '.($invalid ? 2 : 1), $markdown);
            self::assertStringContainsString('Projects checked: 1', $markdown);
            self::assertStringContainsString('Violations: '.($invalid ? 1 : 0), $markdown);
            self::assertSame([], glob($application->getKernel()->getBuildDir().'/*Container.php'));
        }
    }

    public function testMarkdownCommandFindGetAndArgvOutputWorkWithoutBoot(): void
    {
        $this->module('A', '[\\Missing\\One::class]');
        $application = new Application(new TestApplicationKernel($this->source));
        $application->setAutoExit(false);
        foreach ([$application->find('sillbeam:report'), $application->get('sillbeam:report')] as $command) {
            $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
            self::assertSame(1, $tester->execute(['--output' => 'custom.md']));
        }
        $input = new \Symfony\Component\Console\Input\ArgvInput(['console', '--env', 'test', '--output', 'argv.md', 'sillbeam:report']);
        self::assertSame(1, $application->run($input, new \Symfony\Component\Console\Output\BufferedOutput()));
        self::assertFileExists($this->source->directory.'/argv.md');
    }

    public function testMarkdownUnavailableAnalysisAndUsageFailuresUseExitTwo(): void
    {
        $this->source->write('config/packages/sillbeam.yaml', 'sillbeam: { nonexistent_setting: true }');
        $application = new Application(new TestApplicationKernel($this->source));
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        self::assertSame(2, $tester->run(['command' => 'sillbeam:report', '--output' => 'unavailable.md']));
        $markdown = (string) file_get_contents($this->source->directory.'/unavailable.md');
        self::assertStringContainsString('Status: unavailable', $markdown);
        self::assertStringContainsString('nonexistent\\_setting', $markdown);
        self::assertSame(2, $tester->run(['command' => 'sillbeam:report', '--unknown-option' => true]));
        self::assertSame(2, $tester->run(['command' => 'sillbeam:report', '--format' => 'json']));
    }

    public function testMarkdownCommandIsAlsoRegisteredInNormalSymfonyConsole(): void
    {
        $this->module('A');
        $kernel = new TestApplicationKernel($this->source);
        try {
            $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
            $tester = new \Symfony\Component\Console\Tester\CommandTester($application->find('sillbeam:report'));
            self::assertSame(0, $tester->execute([]));
            self::assertFileExists($this->source->directory.'/architecture-report.md');
        } finally {
            $kernel->shutdown();
        }
    }

    public function testMissingConstructorClassAppearsInTextJsonAndMarkdownWithoutContainer(): void
    {
        $this->source->php('src/Module/Hello/HelloModule.php', str_replace('__APP__', $this->source->namespace, <<<'CODE'
#[\Sillbeam\Core\Module\Attribute\SillbeamModule(dependencies: [\Missing\UnknownModule::class])]
final class HelloModule {
    public function __construct(private \__APP__\Missing\ConstructorType $missing) {}
    public function response(): \Symfony\Component\HttpFoundation\Response { return new \Symfony\Component\HttpFoundation\Response(); }
    public function other(): \Symfony\Component\HttpFoundation\Response { return new \Symfony\Component\HttpFoundation\Response(); }
}
CODE));
        [$code, $json] = $this->runReport('json');
        self::assertSame(1, $code);
        $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['code.unknown_class', 'dependency.unknown'], array_column($data['findings'], 'rule'));
        self::assertSame(3, $data['findings'][0]['context']['line']);
        self::assertStringContainsString('Correct the class name', $data['findings'][0]['context']['suggestion']);
        [$code, $text] = $this->runReport('text');
        self::assertSame(1, $code);
        self::assertStringContainsString('2 findings · 2 diagnoses', $text);
        self::assertStringContainsString('Application class cannot be resolved', $text);
        self::assertStringContainsString('Missing\\ConstructorType', $text);
        self::assertStringContainsString('HelloModule.php:3', $text);
        $application = new Application(new TestApplicationKernel($this->source));
        $application->setAutoExit(false);
        self::assertSame(1, (new ApplicationTester($application))->run(['command' => 'sillbeam:report']));
        $markdown = (string) file_get_contents($this->source->directory.'/architecture-report.md');
        self::assertStringContainsString('code.unknown\\_class', $markdown);
        self::assertStringContainsString('Violations: 2', $markdown);
        self::assertStringContainsString('HelloModule.php:3', $markdown);
    }

    public function testApplicationCouplingsAreInformationalInAllReportFormats(): void
    {
        $this->module('A');
        $mail = $this->source->php('src/Service/MailService.php', 'class MailService {}');
        $this->source->php('src/Module/A/Consumer.php', 'class Consumer { public function __construct(public \\'.$mail.' $mail, public \\Psr\\Log\\LoggerInterface $logger) {} }');
        [$code, $json] = $this->runReport('json');
        self::assertSame(0, $code);
        $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(1, $data['schema_version']);
        self::assertSame([], $data['findings']);
        self::assertSame([], $data['modules'][0]['observed_dependencies']);
        self::assertSame([$mail], $data['modules'][0]['application_dependencies']);
        self::assertStringEndsWith('/src/Service/MailService.php', $data['modules'][0]['application_references'][0]['target_file']);
        $this->assertSchemaKeys($data);
        [, $text] = $this->runReport('text');
        self::assertStringNotContainsString('APPLICATION COUPLINGS', $text);
        [, $text] = $this->runReport('text', ['--couplings' => true, '-v' => true]);
        self::assertStringContainsString('APPLICATION COUPLINGS', $text);
        self::assertStringContainsString($mail, $text);
        self::assertStringContainsString('Consumer.php:1', $text);
        $application = new Application(new TestApplicationKernel($this->source));
        $application->setAutoExit(false);
        self::assertSame(0, (new ApplicationTester($application))->run(['command' => 'sillbeam:report']));
        $markdown = (string) file_get_contents($this->source->directory.'/architecture-report.md');
        self::assertStringNotContainsString('Application couplings', $markdown);
        self::assertSame(0, (new ApplicationTester($application))->run(['command' => 'sillbeam:report', '--couplings' => true]));
        $markdown = (string) file_get_contents($this->source->directory.'/architecture-report.md');
        self::assertStringContainsString('Application couplings', $markdown);
        self::assertStringContainsString('MailService', $markdown);
        self::assertStringContainsString('Consumer.php:1', $markdown);
    }

    private function module(string $name, string $dependencies = '[]'): void
    {
        $this->source->php('src/Module/'.$name.'/'.$name.'Module.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule(dependencies: '.$dependencies.')] final class '.$name.'Module {}');
    }

    /** @param array<string, mixed> $arguments
     * @return array{int, string, string}
     */
    private function runReport(string $format, array $arguments = []): array
    {
        $application = new Application(new TestApplicationKernel($this->source));
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $code = $tester->run(['command' => 'sillbeam:architecture', '--format' => $format, ...$arguments], ['decorated' => true, 'capture_stderr_separately' => true]);

        return [$code, $tester->getDisplay(), $tester->getErrorOutput()];
    }
}

final class ReportingPrependBundle extends \Symfony\Component\HttpKernel\Bundle\Bundle
{
    /** @param array<string, mixed> $configuration */
    public function __construct(private readonly array $configuration)
    {
    }

    public function getContainerExtension(): \Symfony\Component\DependencyInjection\Extension\ExtensionInterface
    {
        return new class($this->configuration) extends \Symfony\Component\DependencyInjection\Extension\Extension implements \Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface {
            /** @param array<string, mixed> $configuration */
            public function __construct(private readonly array $configuration)
            {
            }

            public function getAlias(): string
            {
                return 'reporting_prepend';
            }

            public function prepend(\Symfony\Component\DependencyInjection\ContainerBuilder $container): void
            {
                $container->prependExtensionConfig('sillbeam', $this->configuration);
            }

            public function load(array $configs, \Symfony\Component\DependencyInjection\ContainerBuilder $container): void
            {
            }
        };
    }
}
