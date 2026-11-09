<?php

declare(strict_types=1);

namespace Sillbeam\Core\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sillbeam\Core\Console\Application;
use Sillbeam\Core\I18n\UserLocaleStore;
use Sillbeam\Core\Tests\Support\SourceWorkspace;
use Sillbeam\Core\Tests\Support\TestApplicationKernel;
use Symfony\Component\Console\Tester\ApplicationTester;

final class CoreLiveApiTest extends TestCase
{
    private SourceWorkspace $source;

    protected function setUp(): void
    {
        $this->source = new SourceWorkspace();
        $this->source->write('config/packages/framework.yaml', 'framework: { secret: test, test: true }');
    }

    protected function tearDown(): void
    {
        $this->source->remove();
    }

    public function testLiveWithoutContainerCompilation(): void
    {
        $this->module('A');
        $this->source->write('config/services.yaml', "services:\n    broken:\n        class: stdClass\n        arguments: ['@nonexistent_service']\n");
        $this->source->write('config/packages/noisy.php', '<?php echo "incidental output"; return static function (): void {};');
        [$code, $json, $stderr] = $this->runCommand('sillbeam:api');
        self::assertSame(0, $code, $stderr);
        self::assertSame('', $stderr);
        $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('valid', $data['analysis']['status']);
        self::assertTrue($data['architecture']['modules'][0]['active']);
        self::assertSame(['en'], $data['i18n']['included_locales']);
        self::assertArrayNotHasKey('doctor', $data);
    }

    public function testPersonalAndProjectPreferencesDoNotChangeApiLocaleContracts(): void
    {
        $this->module('A');
        $this->source->write('config/packages/sillbeam.yaml', 'sillbeam: { locale: de }');
        $previous = [];
        foreach (['HOME', 'XDG_CONFIG_HOME', 'APPDATA', 'LOCALAPPDATA'] as $name) {
            $previous[$name] = getenv($name);
            putenv($name.'='.$this->source->directory.'/user');
        }
        try {
            UserLocaleStore::fromEnvironment()->set('de');
            foreach ([
                [[], ['en']],
                [['--locale' => 'de'], ['de']],
                [['--profile' => 'analysis'], ['en', 'de']],
            ] as [$options, $locales]) {
                [$code, $json, $stderr] = $this->runCommand('sillbeam:api', $options);
                self::assertSame(0, $code, $stderr);
                $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
                self::assertSame($locales, $data['i18n']['included_locales']);
            }
            [$code, $json, $stderr] = $this->runCommand('sillbeam:api', ['--profile' => 'analysis', '--locale' => 'de']);
            self::assertSame(4, $code);
            self::assertSame('', $json);
            self::assertNotSame('', $stderr);
        } finally {
            foreach ($previous as $name => $value) {
                putenv(false === $value ? $name : $name.'='.$value);
            }
        }
    }

    public function testGroupedViolationRetainsReferencesAndLocalizedDetails(): void
    {
        $a = $this->module('A');
        $b = $this->module('B');
        $ns = $this->source->namespace;
        $this->source->php('src/Module/B/Target.php', 'final class Target {}');
        $formatter = $this->source->php('src/Service/Formatter.php', 'final class Formatter {}');
        $this->source->php('src/Module/A/Presentation.php', 'final class Presentation { public \\'.$formatter.' $formatter; }');
        $this->source->php('src/Module/A/First.php', 'final class First { public \\'.$ns.'\\Module\\B\\Target $target; }');
        $this->source->php('src/Module/A/Second.php', 'final class Second { public \\'.$ns.'\\Module\\B\\Target $target; }');
        [$code, $json, $stderr] = $this->runCommand('sillbeam:api', ['--locale' => 'de']);
        self::assertSame(1, $code, $stderr);
        $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('complete', $data['export']['status']);
        self::assertNull($data['architecture']['modules'][0]['active']);
        $diagnostics = $data['diagnostics'];
        self::assertSame(['D1', 'D1'], array_column($diagnostics['findings'], 'diagnosis_id'));
        self::assertSame([0, 1], $diagnostics['diagnoses'][0]['finding_indexes']);
        self::assertSame([0, 1], array_column($diagnostics['diagnoses'][0]['details'], 'finding_index'));
        self::assertSame(['de'], array_keys($diagnostics['diagnoses'][0]['translations']));
        self::assertStringContainsString('direkte Abhängigkeit', $diagnostics['findings'][0]['translations']['de']['message']);
        self::assertSame('src/Module/A/First.php', $diagnostics['findings'][0]['location']['path']);
        self::assertSame(2, $data['summary']['module_reference_count']);
        self::assertSame(['MR1', 'MR2'], array_column($data['references']['module'], 'id'));
        self::assertSame([$a, $a], array_column($data['references']['module'], 'source_module'));
        self::assertSame([$b, $b], array_column($data['references']['module'], 'target_module'));
        self::assertSame([false, false], array_column($data['references']['module'], 'declared'));
        self::assertSame(1, $data['summary']['application_reference_count']);
        self::assertSame('AR1', $data['references']['application'][0]['id']);
        self::assertSame('informational', $data['references']['application'][0]['classification']);
        self::assertSame($formatter, $data['references']['application'][0]['target_class']);
        self::assertSame('src/Service/Formatter.php', $data['references']['application'][0]['target_file']);
        self::assertSame(1, $diagnostics['diagnoses'][0]['correlation_version']);
        self::assertMatchesRegularExpression('/^sha256:v1:[0-9a-f]{64}$/', $diagnostics['diagnoses'][0]['correlation_key']);

        [$englishCode, $englishJson] = $this->runCommand('sillbeam:api');
        self::assertSame(1, $englishCode);
        $english = json_decode($englishJson, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame($diagnostics['diagnoses'][0]['correlation_key'], $english['diagnostics']['diagnoses'][0]['correlation_key']);
        self::assertSame($data['references'], $english['references']);
        self::assertSame($diagnostics['findings'][0]['message'], $english['diagnostics']['findings'][0]['message']);
        self::assertSame($diagnostics['findings'][0]['context'], $english['diagnostics']['findings'][0]['context']);
        self::assertNotSame($data['metadata']['analysis_run_id'], $english['metadata']['analysis_run_id']);
    }

    public function testValidProjectsExposePrivateOwnershipAndCompiledMembership(): void
    {
        $shared = $this->module('Shared');
        $first = $this->source->php('src/Project/First/FirstProject.php', '#[\Sillbeam\Core\Project\Attribute\SillbeamProject(id: "first", modules: [\\'.$shared.'::class])] final class FirstProject {}');
        $second = $this->source->php('src/Project/Second/SecondProject.php', '#[\Sillbeam\Core\Project\Attribute\SillbeamProject(id: "second")] final class SecondProject {}');
        $private = $this->source->php('src/Project/First/Private/PrivateModule.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(id: "private")] final class PrivateModule {}');
        $this->source->write('config/packages/sillbeam.yaml', "sillbeam:\n    projects:\n        first: {hosts: ['first.test']}\n        second: {hosts: ['second.test']}\n");

        [$code, $json, $stderr] = $this->runCommand('sillbeam:api');
        self::assertSame(0, $code, $stderr.$json);
        $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        $projects = array_column($data['architecture']['projects'], null, 'class');
        $modules = array_column($data['architecture']['modules'], null, 'class');
        self::assertSame(2, $data['summary']['project_count']);
        self::assertEqualsCanonicalizing([$private, $shared], $projects[$first]['modules']);
        self::assertSame([], $projects[$second]['modules']);
        self::assertSame('private', $modules[$private]['scope']);
        self::assertSame($first, $modules[$private]['project']);
        self::assertSame('project:first:private', $modules[$private]['configuration']['key']);
        self::assertSame([$first => 'active', $second => 'unassigned'], array_column($modules[$private]['project_states'], 'state', 'project_class'));
    }

    public function testOutsideSyntaxErrorDoesNotBecomeAnArchitectureFindingInLive(): void
    {
        $this->module('A');
        $this->source->write('src/Service/Broken.php', '<?php class {');
        [$code, $json, $stderr] = $this->runCommand('sillbeam:api');
        self::assertSame(0, $code, $stderr.$json);
        self::assertSame([], json_decode($json, true, flags: \JSON_THROW_ON_ERROR)['diagnostics']['findings']);

        [$legacyCode, $legacyJson] = $this->runCommand('sillbeam:architecture', ['--format' => 'json']);
        self::assertSame(0, $legacyCode);
        self::assertSame([], json_decode($legacyJson, true, flags: \JSON_THROW_ON_ERROR)['findings']);
    }

    public function testOutsideCatalogPathYieldsPartialExportWithoutInventingPaths(): void
    {
        $external = new SourceWorkspace();
        try {
            $external->php('src/Module/A/AModule.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(id: "a")] final class AModule {}');
            $this->source->write('config/packages/sillbeam.yaml', "sillbeam:\n    discovery:\n        modules:\n            - { path: '".$external->directory."/src/Module', namespace: '".$external->namespace."\\Module' }\n");
            [$code, $json, $stderr] = $this->runCommand('sillbeam:api');
            self::assertSame(3, $code, $stderr.$json);
            $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame('valid', $data['analysis']['status']);
            self::assertSame('partial', $data['export']['status']);
            self::assertSame('partial', $data['completeness']['architecture']);
            self::assertSame([], $data['architecture']['modules']);
            self::assertSame('export.path_unavailable', $data['errors'][0]['code']);
            self::assertStringNotContainsString($external->directory, $json);
        } finally {
            $external->remove();
        }
    }

    public function testUnavailableAndUnsupportedInvocationsHaveDifferentOutput(): void
    {
        $this->source->write('config/packages/sillbeam.yaml', 'sillbeam: { nonexistent_setting: true }');
        [$code, $json] = $this->runCommand('sillbeam:api');
        self::assertSame(2, $code);
        $unavailable = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('unavailable', $unavailable['analysis']['status']);
        self::assertSame('unavailable', $unavailable['completeness']['references']);
        self::assertSame([], $unavailable['diagnostics']['findings']);
        self::assertSame('export.analysis_unavailable', $unavailable['errors'][0]['code']);

        [$code, $json, $stderr] = $this->runCommand('sillbeam:api', ['--profile' => 'analysis', '--locale' => 'de']);
        self::assertSame(4, $code);
        self::assertSame('', $json);
        self::assertNotSame('', $stderr);
    }

    public function testFatalSourceFindingIsPreservedWithUnavailableStatus(): void
    {
        $this->source->write('src/Module/A/Broken.php', '<?php class {');
        [$code, $json, $stderr] = $this->runCommand('sillbeam:api');
        self::assertSame(2, $code, $stderr);
        $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('unavailable', $data['analysis']['status']);
        self::assertSame('source.parse', $data['diagnostics']['findings'][0]['rule']);
        self::assertSame('fatal', $data['diagnostics']['diagnoses'][0]['severity']);
        self::assertSame('src/Module/A/Broken.php', $data['diagnostics']['findings'][0]['location']['path']);
    }

    public function testInvalidOptionsAndSerializationFailureProduceOnlyStderr(): void
    {
        $this->source->php('src/Module/A/AModule.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(id: "a", description: "\xff")] final class AModule {}');
        foreach ([['--locale' => 'de-DE'], ['--schema-version' => '2'], ['--schema-version' => 'x'], ['--invalid-option' => true], []] as $options) {
            [$code, $json, $stderr] = $this->runCommand('sillbeam:api', $options);
            self::assertSame(4, $code);
            self::assertSame('', $json);
            self::assertNotSame('', $stderr);
        }
    }

    public function testCapabilitiesDoNotReadApplicationConfiguration(): void
    {
        $this->source->write('config/packages/sillbeam.yaml', 'sillbeam: { nonexistent_setting: true }');
        [$code, $json, $stderr] = $this->runCommand('sillbeam:api:capabilities', ['--format' => 'json']);
        self::assertSame(0, $code, $stderr);
        $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['live', 'analysis'], array_keys($data['profiles']));
        self::assertContains('inspect_sources', $data['operations']);
    }

    /** @return class-string */
    private function module(string $name): string
    {
        return $this->source->php('src/Module/'.$name.'/'.$name.'Module.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule(id: "'.strtolower($name).'")] final class '.$name.'Module {}');
    }

    /** @param array<string, mixed> $options
     * @return array{int, string, string}
     */
    private function runCommand(string $name, array $options = []): array
    {
        $application = new Application(new TestApplicationKernel($this->source));
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $code = $tester->run(['command' => $name, '--no-ansi' => true, '--no-interaction' => true] + $options, ['capture_stderr_separately' => true]);

        return [$code, $tester->getDisplay(), $tester->getErrorOutput()];
    }
}
