<?php

declare(strict_types=1);

namespace Sillbeam\Core\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sillbeam\Core\Console\Application;
use Sillbeam\Core\Tests\Support\SourceWorkspace;
use Sillbeam\Core\Tests\Support\TestApplicationKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application as NativeApplication;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\ApplicationTester;

final class ConsoleLocaleTest extends TestCase
{
    public function testLocalePreferencesAndExplicitOverride(): void
    {
        $source = new SourceWorkspace();
        $restore = $this->isolateUserLocale($source);
        try {
            $source->write('config/packages/sillbeam.yaml', 'sillbeam: { locale: de }');
            $kernel = new TestApplicationKernel($source);
            $app = new Application($kernel);
            $app->setAutoExit(false);
            $tester = new ApplicationTester($app);
            self::assertSame(0, $tester->run(['command' => 'sillbeam:locale', '--set' => 'en']));
            self::assertSame(0, $tester->run(['command' => 'sillbeam:architecture']));
            self::assertStringContainsString('Architecture', $tester->getDisplay());
            self::assertSame(0, $tester->run(['command' => 'sillbeam:architecture', '--locale' => 'de']));
            self::assertStringContainsString('Architektur', $tester->getDisplay());
            $output = new BufferedOutput();
            self::assertSame(0, $app->run(new ArgvInput(['console', '--locale=en', 'sillbeam:architecture', '--locale', 'de']), $output));
            self::assertStringContainsString('Architektur', $output->fetch());
            self::assertSame(2, $tester->run(['command' => 'sillbeam:locale', '--set' => 'de', '--locale' => 'fr']));
            self::assertSame(2, $tester->run(['command' => 'sillbeam:locale', '--set' => 'de', '--reset' => true]));
            self::assertSame(0, $tester->run(['command' => 'sillbeam:locale', '--reset' => true]));
            self::assertSame(0, $tester->run(['command' => 'sillbeam:architecture']));
            self::assertStringContainsString('Architektur', $tester->getDisplay());
            foreach (['module' => 'Billing', 'project' => 'Shop'] as $kind => $name) {
                self::assertSame(0, $tester->run(['command' => 'sillbeam:make:'.$kind, 'Name' => $name]));
                self::assertStringContainsString('Erstellt:', $tester->getDisplay());
            }
            self::assertSame(0, $tester->run(['command' => 'sillbeam:about']));
            self::assertStringContainsString('Anwendung:', $tester->getDisplay());
            foreach (['sillbeam:api:capabilities' => 'Core-Data-Profile', 'sillbeam:inspect:sources' => 'Quellinventar'] as $command => $text) {
                self::assertSame(0, $tester->run(['command' => $command, '--help' => true]));
                self::assertStringContainsString($text, $tester->getDisplay());
            }
        } finally {
            $restore();
            $source->remove();
        }
    }

    public function testLocalizedHumanCommandsPreserveLegacyJsonAndGraphIdentities(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->write('config/packages/framework.yaml', 'framework: { secret: test }');
            $source->php('src/Module/Billing/BillingModule.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(dependencies: [\Missing\Module::class])] final class BillingModule {}');
            $kernel = new TestApplicationKernel($source);
            $app = new Application($kernel);
            $app->setAutoExit(false);
            $tester = new ApplicationTester($app);
            foreach (['sillbeam:architecture', 'sillbeam:doctor'] as $command) {
                $input = ['command' => $command, '--format' => 'json'];
                $expected = $tester->run($input + ['--locale' => 'en']);
                $json = $tester->getDisplay();
                self::assertSame(1, json_decode($json, true, flags: \JSON_THROW_ON_ERROR)['schema_version']);
                self::assertSame($expected, $tester->run($input + ['--locale' => 'de']));
                self::assertSame($json, $tester->getDisplay());
            }
            $tests = [
                ['sillbeam:architecture', ['--all' => true], 'Architektur', 1],
                ['sillbeam:inspect:module', ['module' => 'billing'], 'Modulinspektion', 1],
                ['sillbeam:graph', ['--format' => 'mermaid'], 'Analyse: Verletzungen', 1],
                ['sillbeam:report', ['--output' => 'architektur.md'], 'Architekturbericht wurde', 1],
                ['sillbeam:graph', ['--output' => $source->directory.'/graph.svg'], 'Architektur-SVG', 1],
            ];
            foreach ($tests as [$command, $options, $text, $status]) {
                self::assertSame($status, $tester->run(['command' => $command, '--locale' => 'de'] + $options), $command.' '.$tester->getDisplay());
                self::assertStringContainsString($text, $tester->getDisplay());
            }
            $markdown = (string) file_get_contents($source->directory.'/architektur.md');
            self::assertStringContainsString('# Sillbeam-Architekturbericht', $markdown);
            self::assertStringContainsString('dependency.unknown', $markdown);
            $svg = (string) file_get_contents($source->directory.'/graph.svg');
            self::assertStringContainsString('Zustand=unbekannt', $svg);
            self::assertStringContainsString('dependency.unknown', $svg);
            self::assertDirectoryDoesNotExist($kernel->getCacheDir().'/pools');

            $native = new NativeApplication($kernel);
            $native->setAutoExit(false);
            $nativeTester = new ApplicationTester($native);
            self::assertSame(1, $nativeTester->run(['command' => 'sillbeam:architecture', '--locale' => 'de', '--all' => true]));
            self::assertStringContainsString('Architektur', $nativeTester->getDisplay());
            self::assertStringContainsString('unbekannt', $nativeTester->getDisplay());
        } finally {
            $source->remove();
        }
    }

    public function testDoctorResolvesYamlLocaleWithoutExecutingApplicationConfiguration(): void
    {
        $source = new SourceWorkspace();
        $restore = $this->isolateUserLocale($source);
        try {
            $source->write('config/packages/sillbeam.yaml', 'sillbeam: { locale: de }');
            $source->write('config/packages/unresolved.php', '<?php file_put_contents(__DIR__."/executed", "unexpected");');
            $app = new Application(new TestApplicationKernel($source));
            $app->setAutoExit(false);
            $tester = new ApplicationTester($app);
            $status = $tester->run(['command' => 'sillbeam:doctor']);
            self::assertContains($status, [1, 2]);
            self::assertStringContainsString('nicht verifiziert', $tester->getDisplay());
            self::assertFileDoesNotExist($source->directory.'/config/packages/executed');
        } finally {
            $restore();
            $source->remove();
        }
    }

    public function testHelpAndLocaleManagementDoNotBuildOrDiscoverArchitecture(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->php('src/Module/Broken/BrokenModule.php', 'throw new \LogicException("descriptor must not be loaded"); #[\Sillbeam\Core\Module\Attribute\SillbeamModule] final class BrokenModule {}');
            $kernel = new TestApplicationKernel($source);
            $app = new Application($kernel);
            $app->setAutoExit(false);
            $tester = new ApplicationTester($app);
            foreach ([
                'sillbeam:architecture' => 'Architekturbefunde',
                'sillbeam:doctor' => 'Symfony-Installation',
                'sillbeam:report' => 'Architekturbericht',
                'sillbeam:inspect:module' => 'Abhängigkeiten',
                'sillbeam:graph' => 'Architekturgraphen',
                'sillbeam:make:module' => 'Moduldeskriptor',
                'sillbeam:make:project' => 'Project-Deskriptor',
                'sillbeam:about' => 'Laufzeit- und Modulinformationen',
                'sillbeam:api' => 'Architekturstand',
            ] as $command => $text) {
                self::assertSame(0, $tester->run(['command' => $command, '--help' => true, '--locale' => 'de']), $command);
                self::assertStringContainsString($text, $tester->getDisplay());
            }
            self::assertSame(0, $tester->run(['command' => 'sillbeam:locale', '--locale' => 'de']));
            self::assertStringContainsString('Effektive Sprache: de', $tester->getDisplay());
            self::assertSame(2, $tester->run(['command' => 'sillbeam:architecture', '--locale' => 'fr']));
            self::assertStringContainsString('fr', $tester->getDisplay());
            self::assertDirectoryDoesNotExist($kernel->getCacheDir());
        } finally {
            $source->remove();
        }
    }

    /** @return \Closure(): void */
    private function isolateUserLocale(SourceWorkspace $source): \Closure
    {
        $previous = [];
        foreach (['HOME', 'XDG_CONFIG_HOME', 'APPDATA', 'LOCALAPPDATA'] as $name) {
            $previous[$name] = getenv($name);
            putenv($name.'='.$source->directory.'/user');
        }

        return static function () use ($previous): void {
            foreach ($previous as $name => $value) {
                putenv(false === $value ? $name : $name.'='.$value);
            }
        };
    }
}
