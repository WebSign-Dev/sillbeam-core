<?php

declare(strict_types=1);

namespace Sillbeam\Core\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sillbeam\Core\Architecture\ArchitectureAnalyzer;
use Sillbeam\Core\Architecture\ArchitectureReport;
use Sillbeam\Core\Architecture\Console\ArchitectureRenderer;
use Sillbeam\Core\Architecture\DiscoveryOptions;
use Sillbeam\Core\Tests\Support\SourceWorkspace;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Output\BufferedOutput;

/** Real temporary Symfony consumer, own Kernel/bin/console/PSR-4 loader; no Console doubles. */
final class ProductionCliTest extends TestCase
{
    private SourceWorkspace $source;

    protected function setUp(): void
    {
        $this->source = new SourceWorkspace();
        $this->source->write('config/packages/framework.yaml', 'framework: {secret: cli-test, test: true}');
        $this->source->write('config/services.yaml', 'services: {}');
        $kernel = $this->source->php('src/Kernel.php', <<<'PHP'
final class Kernel extends \Symfony\Component\HttpKernel\Kernel {
    use \Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
    public function getProjectDir(): string { return \dirname(__DIR__); }
    public function registerBundles(): iterable {
        yield new \Symfony\Bundle\FrameworkBundle\FrameworkBundle();
        yield new \Sillbeam\Core\SillbeamCoreBundle();
    }
}
PHP);
        $autoload = var_export(\dirname(__DIR__, 2).'/vendor/autoload.php', true);
        $prefix = var_export($this->source->namespace.'\\', true);
        $this->source->write('bin/console', '<?php require '.$autoload.'; $loader = new \Composer\Autoload\ClassLoader(); $loader->addPsr4('.$prefix.', \dirname(__DIR__)."/src"); $loader->register(true); $app = new \Sillbeam\Core\Console\Application(new \\'.$kernel.'("test", true)); $app->run();');
    }

    protected function tearDown(): void
    {
        $this->source->remove();
    }

    public function testExecutedConsumerMatchesRendererAtEveryWidthAnsiAndRedirectModeWithBrokenContainer(): void
    {
        $this->modules(true);
        $this->source->write('config/services.yaml', "services:\n    broken:\n        class: stdClass\n        public: true\n        arguments: ['@missing_service']\n");
        $analysis = (new ArchitectureAnalyzer())->analyze(new DiscoveryOptions($this->source->directory.'/src', $this->source->namespace.'\\'));
        $report = ArchitectureReport::fromAnalysis($analysis, projectDir: $this->source->directory);
        foreach ([52, 60, 84, 90, 110] as $width) {
            foreach ([false, true] as $ansi) {
                $expected = new BufferedOutput(decorated: $ansi);
                (new ArchitectureRenderer($expected, $width))->render($report->presentation);
                [$code, $stdout, $stderr] = $this->runConsole(['sillbeam:architecture', $ansi ? '--ansi' : '--no-ansi'], $width, !$ansi);
                self::assertSame(1, $code, $stderr);
                self::assertSame('', $stderr);
                self::assertSame($expected->fetch(), $stdout);
                if (!$ansi) {
                    foreach (explode("\n", $stdout) as $line) {
                        self::assertLessThanOrEqual(min(90, $width), Helper::width($line));
                    }
                }
            }
        }
        [$code, $json, $stderr] = $this->runConsole(['--format=json', 'sillbeam:architecture', '-vvv', '--ansi']);
        self::assertSame(1, $code);
        self::assertSame('', $stderr);
        self::assertSame($report->toJson()."\n", $json);
        self::assertStringNotContainsString("\033", $json);
    }

    public function testAdapterBindsSelectorsBeforeCommandAndRetainsOriginalFindings(): void
    {
        $this->modules(true);
        foreach ([['--issue=2', 'sillbeam:architecture'], ['sillbeam:architecture', '--issue=2']] as $args) {
            [$code, $stdout, $stderr] = $this->runConsole([...$args, '--no-ansi']);
            self::assertSame(1, $code, $stderr);
            self::assertStringContainsString('ORIGINAL FINDINGS', $stdout);
            self::assertStringContainsString('MissingCatalog', explode('ORIGINAL FINDINGS', $stdout)[1]);
            self::assertSame(1, substr_count($stdout, 'WHAT IS WRONG'));
        }
        [$code, $all] = $this->runConsole(['--all', 'sillbeam:architecture', '--no-ansi']);
        self::assertSame(1, $code);
        self::assertSame(2, substr_count($all, 'WHAT IS WRONG'));
        self::assertSame(2, substr_count($all, 'ORIGINAL FINDINGS'));
        [$code, $verbose] = $this->runConsole(['sillbeam:architecture', '-v', '--no-ansi']);
        self::assertSame(1, $code);
        self::assertStringContainsString('dependency.unknown', $verbose);
        // Full paths may wrap visually; no part is shortened or omitted.
        $unwrapped = preg_replace('/\s+/', '', $verbose);
        self::assertIsString($unwrapped);
        self::assertStringContainsString($this->source->directory.'/src/Module/User/UserModule.php', $unwrapped);
    }

    public function testHelpAndUsageWorkWithoutBootingOrRunningTheAnalyzer(): void
    {
        $this->source->write('src/Invalid.php', '<?php class {');
        [$code, $help, $stderr] = $this->runConsole(['--issue=2', 'sillbeam:architecture', '--help', '--no-ansi']);
        self::assertSame(0, $code, $help.' '.$stderr);
        foreach (['--all', '--issue', '--couplings', '--format=json', 'current analysis run'] as $text) {
            self::assertStringContainsString($text, $help);
        }
        [$code, $reportHelp, $stderr] = $this->runConsole(['sillbeam:report', '--help']);
        self::assertSame(0, $code, $stderr);
        foreach (['--couplings', '--output', 'atomic', 'Exit codes:', 'parent directory must exist'] as $text) {
            self::assertStringContainsString($text, $reportHelp);
        }
        self::assertFileDoesNotExist($this->source->directory.'/architecture-report.md');
        foreach ([['--all', '--issue=1'], ['--issue=0'], ['--format=json', '--couplings'], ['--format=json', '--unknown'], ['--ansi', '--no-ansi']] as $args) {
            [$code, $stdout, $stderr] = $this->runConsole(['sillbeam:architecture', ...$args]);
            self::assertSame(2, $code, $stderr);
            self::assertSame('', $stderr);
            self::assertStringNotContainsString('Cannot parse', $stdout);
            if (\in_array('--format=json', $args, true)) {
                $data = json_decode($stdout, true, flags: \JSON_THROW_ON_ERROR);
                self::assertSame('report.execution', $data['findings'][0]['rule']);
            }
        }
        [$code] = $this->runConsole(['sillbeam:architecture', '--issue=99']);
        self::assertSame(2, $code);
    }

    public function testCouplingsAreOptInAndRealAndQuietPreservesStatus(): void
    {
        $this->modules(false);
        $mail = $this->source->php('src/Service/ActualMail.php', 'final class ActualMail {}');
        $this->source->php('src/Module/User/Consumer.php', 'final class Consumer { public \\'.$mail.' $mail; }');
        [$code, $stdout, $stderr] = $this->runConsole(['sillbeam:architecture']);
        self::assertSame(0, $code, $stderr);
        self::assertStringContainsString('Architecture valid', $stdout);
        self::assertStringNotContainsString('APPLICATION COUPLINGS', $stdout);
        self::assertStringNotContainsString('ActualMail', $stdout);
        self::assertStringNotContainsString("\033", $stdout); // Real pipe, automatic ANSI detection.
        [$code, $stdout] = $this->runConsole(['sillbeam:architecture', '--couplings', '--no-ansi', '-v']);
        self::assertSame(0, $code);
        self::assertStringContainsString('APPLICATION COUPLINGS', $stdout);
        self::assertStringContainsString('ActualMail', $stdout);
        self::assertStringContainsString('Informational only', $stdout);
        [$code, , $stderr] = $this->runConsole(['sillbeam:report']);
        self::assertSame(0, $code, $stderr);
        self::assertStringNotContainsString('Application couplings', (string) file_get_contents($this->source->directory.'/architecture-report.md'));
        [$code, , $stderr] = $this->runConsole(['--couplings', 'sillbeam:report', '--output=with-couplings.md']);
        self::assertSame(0, $code, $stderr);
        $markdown = (string) file_get_contents($this->source->directory.'/with-couplings.md');
        foreach (['Application couplings', 'ActualMail', 'Consumer.php:1', 'ActualMail.php', 'not architecture violations'] as $text) {
            self::assertStringContainsString($text, $markdown);
        }
        foreach ([[], ['--format=json']] as $args) {
            [$code, $stdout, $stderr] = $this->runConsole(['sillbeam:architecture', '-q', ...$args]);
            self::assertSame(0, $code);
            self::assertSame('', $stdout);
            self::assertSame('', $stderr);
        }
        $this->source->write('src/Module/Bad/Bad.php', '<?php class {');
        [$code, $stdout] = $this->runConsole(['sillbeam:architecture', '-q']);
        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        [$code, , $stderr] = $this->runConsole(['sillbeam:report', '--output=fatal.md']);
        self::assertSame(2, $code, $stderr);
        $markdown = (string) file_get_contents($this->source->directory.'/fatal.md');
        self::assertStringContainsString('Status: unavailable', $markdown);
        self::assertStringContainsString('Analysis complete: no', $markdown);
        self::assertStringContainsString('source.parse', $markdown);
    }

    public function testLiteralProjectSelectionAndDependencyAdviceWorkInFreshConsumerProcesses(): void
    {
        $this->modules(false);
        $prefix = $this->source->namespace;
        $catalog = $prefix.'\\Module\\Catalog\\CatalogModule';
        $user = $prefix.'\\Module\\User\\UserModule';
        $this->source->php('src/Project/Shop/ShopProject.php', '#[\\Sillbeam\\Core\\Project\\Attribute\\SillbeamProject(modules: [\\'.$user.'::class])] final class ShopProject {}');
        $this->source->php('src/Module/Catalog/Item.php', 'final class Item {}');
        $this->source->php('src/Module/User/Consumer.php', 'final class Consumer { public \\'.$prefix.'\\Module\\Catalog\\Item $item; }');
        [$code, $text, $stderr] = $this->runConsole(['sillbeam:architecture', '--all', '--no-ansi']);
        self::assertSame(1, $code, $stderr);
        self::assertStringContainsString('no direct dependency declaration', $text);
        $unwrapped = preg_replace('/\s+/', '', $text) ?? '';
        if (1 !== preg_match('/'.preg_quote('\\'.$catalog.'::class', '/').'/', $unwrapped, $match)) {
            self::fail($text);
        }
        $this->source->php('src/Project/Shop/ShopProject.php', '#[\\Sillbeam\\Core\\Project\\Attribute\\SillbeamProject(modules: [\\'.$user.'::class, '.$match[0].'])] final class ShopProject {}');
        [$code, $json, $stderr] = $this->runConsole(['sillbeam:architecture', '--format=json']);
        self::assertSame(1, $code, $stderr);
        self::assertSame(['code.undeclared_dependency'], array_column(json_decode($json, true, flags: \JSON_THROW_ON_ERROR)['findings'], 'rule'));
        [$code, $text] = $this->runConsole(['sillbeam:architecture', '--no-ansi']);
        self::assertSame(1, $code);
        self::assertStringNotContainsString('IMPORTANT CONTEXT', $text);
        self::assertStringContainsString('No conflicting dependency was detected', $text);
        $unwrapped = preg_replace('/\s+/', '', $text) ?? '';
        if (1 !== preg_match('/'.preg_quote('\\'.$catalog.'::class', '/').'/', $unwrapped, $match)) {
            self::fail($text);
        }
        $this->source->php('src/Module/User/UserModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule(dependencies: ['.$match[0].'])] final class UserModule {}');
        [$code, $json, $stderr] = $this->runConsole(['sillbeam:architecture', '--format=json']);
        self::assertSame(0, $code, $stderr);
        self::assertSame([], json_decode($json, true, flags: \JSON_THROW_ON_ERROR)['findings']);
    }

    public function testDescriptorAdviceCannotResolveToTheImportedUserEntity(): void
    {
        // Use the exact application names in fresh CLI processes to avoid App fixture collisions.
        $console = file_get_contents($this->source->directory.'/bin/console');
        self::assertIsString($console);
        $this->source->write('bin/console', str_replace('$loader->register(true);', '$loader->addPsr4("App\\\\", \\dirname(__DIR__)."/src"); $loader->register(true);', $console));
        $this->source->write('config/packages/sillbeam.yaml', "sillbeam:\n    discovery:\n        namespace: 'App\\'\n");
        $this->source->write('src/Module/User/UserModule.php', '<?php namespace App\\Module\\User; #[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class UserModule {}');
        $this->source->write('src/Module/User/Entity/User.php', '<?php namespace App\\Module\\User\\Entity; final class User {}');
        $this->source->write('src/Module/User/Service/User.php', '<?php namespace App\\Module\\User\\Service; final class User {}');
        $descriptor = '<?php namespace App\\Module\\Portrait; use App\\Module\\User\\Entity\\User; #[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule(dependencies: [%s])] final class PortraitModule {}';
        $this->source->write('src/Module/Portrait/PortraitModule.php', \sprintf($descriptor, ''));
        $this->source->write('src/Module/Portrait/Consumer.php', '<?php namespace App\\Module\\Portrait; use App\\Module\\User\\Entity\\User; final class Consumer { public User $user; }');
        $expression = '\\App\\Module\\User\\UserModule::class';
        foreach ([[], ['--all'], ['--issue=1'], ['-v']] as $options) {
            [$code, $text, $stderr] = $this->runConsole(['sillbeam:architecture', ...$options, '--no-ansi']);
            self::assertSame(1, $code, $stderr);
            $unwrapped = preg_replace('/\s+/', '', $text) ?? '';
            self::assertStringContainsString('Portrait→User', $unwrapped);
            self::assertStringContainsString('add'.$expression.'totheSillbeamModuledependencieslist', $unwrapped);
            self::assertStringNotContainsString('addUser::class', $unwrapped);
            self::assertStringNotContainsString('add\\App\\Module\\User\\Entity\\User::class', $unwrapped);
            self::assertStringNotContainsString('add\\App\\Module\\User\\Service\\User::class', $unwrapped);
        }
        [$code, $text, $stderr] = $this->runConsole(['sillbeam:report', '--no-ansi']);
        self::assertSame(1, $code, $stderr);
        $markdown = file_get_contents($this->source->directory.'/architecture-report.md');
        self::assertIsString($markdown);
        self::assertStringContainsString('add '.str_replace('\\', '\\\\', $expression).' to the SillbeamModule dependencies list', $markdown);
        self::assertStringNotContainsString('add User::class', $markdown);

        // Copy the actual displayed PHP token, retaining the conflicting Entity import.
        [$code, $text] = $this->runConsole(['sillbeam:architecture', '--no-ansi']);
        self::assertSame(1, $code);
        $unwrapped = preg_replace('/\s+/', '', $text) ?? '';
        if (1 !== preg_match('/add(\\\\[A-Za-z_][A-Za-z0-9_\\\\]*::class)totheSillbeamModule/', $unwrapped, $match)) {
            self::fail($text);
        }
        self::assertSame($expression, $match[1]);
        $this->source->write('src/Module/Portrait/PortraitModule.php', \sprintf($descriptor, $match[1]));
        [$code, $json, $stderr] = $this->runConsole(['sillbeam:architecture', '--format=json']);
        self::assertSame(0, $code, $stderr);
        self::assertSame([], json_decode($json, true, flags: \JSON_THROW_ON_ERROR)['findings']);

        // Demonstrate why the old short token is unsafe with this real PHP import.
        $this->source->write('src/Module/Portrait/PortraitModule.php', \sprintf($descriptor, 'User::class'));
        [$code, $json, $stderr] = $this->runConsole(['sillbeam:architecture', '--format=json']);
        self::assertSame(1, $code, $stderr);
        $findings = json_decode($json, true, flags: \JSON_THROW_ON_ERROR)['findings'];
        self::assertContains('dependency.unknown', array_column($findings, 'rule'));
    }

    public function testRelatedCommandsUseTheSameStyleAndPreserveTheirActualEffects(): void
    {
        $this->modules(false);
        [$code, $about, $stderr] = $this->runConsole(['sillbeam:about', '--no-ansi']);
        self::assertSame(0, $code, $stderr);
        self::assertStringContainsString('SILLBEAM CORE  / About', $about);
        self::assertStringContainsString('Symfony:', $about);
        [$code, $generated, $stderr] = $this->runConsole(['sillbeam:make:module', 'billing', '--no-ansi']);
        self::assertSame(0, $code, $stderr);
        self::assertStringContainsString('✓ Created', $generated);
        self::assertFileExists($this->source->directory.'/src/Module/Billing/BillingModule.php');
        [$code, $quiet, $stderr] = $this->runConsole(['sillbeam:make:project', 'shop', '-q']);
        self::assertSame(0, $code, $stderr);
        self::assertSame('', $quiet);
        self::assertFileExists($this->source->directory.'/src/Project/Shop/ShopProject.php');
        [$code, $report, $stderr] = $this->runConsole(['sillbeam:report', '--no-ansi']);
        self::assertSame(0, $code, $stderr);
        self::assertStringContainsString('✓ Architecture report written', $report);
        self::assertStringNotContainsString('WHAT IS WRONG', $report);
        self::assertFileExists($this->source->directory.'/architecture-report.md');
    }

    public function testExceptionTextCannotInjectFormatterMarkupOrTerminalControls(): void
    {
        $message = '<error>literal</error>'."\033[2J\033]52;c;secret\007";
        $this->source->write('config/packages/failure.php', '<?php throw new \RuntimeException('.var_export($message, true).');');
        [$code, $stdout, $stderr] = $this->runConsole(['sillbeam:architecture', '--no-ansi']);
        self::assertSame(2, $code, $stderr);
        self::assertStringContainsString('<error>literal</error>', $stdout);
        self::assertStringNotContainsString("\033", $stdout);
        self::assertStringNotContainsString('secret', $stdout);
        self::assertStringContainsString('Architecture analysis unavailable', $stdout);
    }

    private function modules(bool $invalid): void
    {
        foreach (['User', 'Catalog'] as $name) {
            $dependencies = $invalid ? '[\\Missing\\Missing'.$name.'::class]' : '[]';
            $this->source->php('src/Module/'.$name.'/'.$name.'Module.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(dependencies: '.$dependencies.')] final class '.$name.'Module {}');
        }
    }

    /** @param list<string> $arguments
     * @return array{int, string, string}
     */
    private function runConsole(array $arguments, int $width = 84, bool $pipe = true): array
    {
        $stdout = $this->source->directory.'/stdout.txt';
        $stderr = $this->source->directory.'/stderr.txt';
        $environment = array_merge(getenv(), ['COLUMNS' => (string) $width, 'SHELL_VERBOSITY' => '0']);
        $process = proc_open([\PHP_BINARY, 'bin/console', ...$arguments, '--no-interaction'], [\STDIN, $pipe ? ['pipe', 'w'] : ['file', $stdout, 'w'], ['file', $stderr, 'w']], $pipes, $this->source->directory, $environment);
        self::assertIsResource($process);
        if ($pipe) {
            $text = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
        } else {
            $text = null;
        }
        $code = proc_close($process);
        $text ??= file_get_contents($stdout);
        $error = file_get_contents($stderr);
        self::assertIsString($text);
        self::assertIsString($error);

        return [$code, $text, $error];
    }
}
