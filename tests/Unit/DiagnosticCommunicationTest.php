<?php

declare(strict_types=1);

namespace Sillbeam\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sillbeam\Core\Architecture\ArchitectureAnalysisResult;
use Sillbeam\Core\Architecture\ArchitectureAnalyzer;
use Sillbeam\Core\Architecture\ArchitectureReport;
use Sillbeam\Core\Architecture\Console\ArchitectureRenderer;
use Sillbeam\Core\Architecture\Console\ReportRenderer;
use Sillbeam\Core\Architecture\Diagnostic\DiagnosticCollection;
use Sillbeam\Core\Architecture\DiscoveryOptions;
use Sillbeam\Core\Tests\Support\SourceWorkspace;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Output\BufferedOutput;

final class DiagnosticCommunicationTest extends TestCase
{
    private SourceWorkspace $source;

    protected function setUp(): void
    {
        $this->source = new SourceWorkspace();
    }

    protected function tearDown(): void
    {
        $this->source->remove();
    }

    public function testAllowedUndeclaredDependencyNamesTheRealEditAndDeduplicatesResolutions(): void
    {
        $catalog = $this->module('Catalog');
        $user = $this->module('User');
        $port = $this->source->php('src/Module/Catalog/Port.php', 'interface Port {}');
        $this->source->php('src/Module/User/Consumer.php', "final class Consumer {\n public \\".$port." \$first;\n public \\".$port." \$second;\n}");
        $analysis = $this->analyze();
        $json = ArchitectureReport::fromAnalysis($analysis)->toJson();
        $collection = DiagnosticCollection::fromAnalysis($analysis);
        self::assertSame(2, $collection->findingCount());
        self::assertSame(1, $collection->diagnosisCount());
        $diagnosis = $collection->diagnoses[0];
        self::assertCount(1, $diagnosis->resolutions);
        $resolution = $diagnosis->resolutions[0];
        self::assertSame('option', $resolution->kind);
        self::assertStringContainsString($catalog.'::class', implode(' ', $resolution->steps));
        self::assertStringContainsString($this->source->directory.'/src/Module/User/UserModule.php', implode(' ', $resolution->steps));
        self::assertStringContainsString('Rerun architecture analysis', implode(' ', $resolution->steps));
        self::assertSame([2, 3], array_column(array_column($diagnosis->findings, 'context'), 'line'));
        self::assertSame($analysis->findings, $collection->findings);
        self::assertSame($json, ArchitectureReport::fromAnalysis($analysis)->toJson());
        self::assertNull($analysis->architecture);
        // Apply the proposed descriptor edit and reanalyze in a fresh process: descriptors are executable PHP.
        $this->source->php('src/Module/User/UserModule.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(dependencies: [\\'.$catalog.'::class])] final class UserModule {}');
        self::assertSame([], $this->freshRules());
        self::assertNotSame($catalog, $user);
    }

    public function testPortraitAdminAdviceIsPlainConsistentAndLiterallyExecutable(): void
    {
        $admin = $this->module('Admin');
        $this->module('Portrait');
        $this->reference('src/Module/Portrait', 'src/Module/Admin');
        $analysis = $this->analyze();
        $report = ArchitectureReport::fromAnalysis($analysis);
        $json = $report->toJson();
        $issue = $report->presentation['issues'][0];
        self::assertNull($issue['caution']);
        self::assertStringContainsString('No conflicting dependency was detected', $issue['impact']);
        $step = implode(' ', $issue['steps']);
        self::assertStringContainsString('\\'.$admin.'::class', $step);
        self::assertStringContainsString('Portrait/PortraitModule.php', $step);
        foreach ([52, 60, 84, 90, 110] as $width) {
            foreach ([false, true] as $ansi) {
                $output = new BufferedOutput(decorated: $ansi);
                (new ArchitectureRenderer($output, $width))->render($report->presentation);
                $text = preg_replace('/\x1B\[[0-9;]*m/', '', $output->fetch()) ?? '';
                self::assertStringNotContainsString('IMPORTANT CONTEXT', $text);
                self::assertStringNotContainsString('witnessed', $text);
                foreach (explode("\n", $text) as $line) {
                    self::assertLessThanOrEqual(min($width, 90), Helper::width($line), $line);
                }
            }
        }
        $markdown = (new ReportRenderer($report))->markdown('test', new \DateTimeImmutable());
        self::assertStringContainsString('No conflicting dependency was detected', $markdown);
        self::assertStringContainsString(str_replace('\\', '\\\\', '\\'.$admin.'::class'), $markdown);
        self::assertSame($json, $report->toJson());
        if (1 !== preg_match('/(\\\\[A-Za-z_][A-Za-z0-9_\\\\]*::class)/', $step, $match)) {
            self::fail($step);
        }
        $this->source->php('src/Module/Portrait/PortraitModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule(dependencies: ['.$match[1].'])] final class PortraitModule {}');
        self::assertSame([], $this->freshRules());
    }

    public function testActivationLimitsAreNotHiddenByTheSimpleDependencyMessage(): void
    {
        $admin = $this->module('Admin');
        $portrait = $this->module('Portrait');
        $this->reference('src/Module/Portrait', 'src/Module/Admin');
        $this->project('Shop', [$portrait]);
        $this->project('Other', [$admin]);
        $analysis = $this->analyze(['projects' => ['shop' => ['hosts' => ['shop.test']], 'other' => ['hosts' => ['other.test']]]]);
        $diagnosis = DiagnosticCollection::fromAnalysis($analysis)->diagnoses[0];
        self::assertStringNotContainsString('No conflicting dependency was detected', $diagnosis->explanation);
        self::assertStringContainsString('activate', strtolower($diagnosis->explanation));
        $view = ArchitectureReport::fromAnalysis($analysis)->presentation;
        self::assertNotNull($view['issues'][0]['caution']);
    }

    public function testPotentialCycleShowsTheActualReturnChainAndAllDeclaringDescriptors(): void
    {
        $user = $this->module('User');
        $bridge = $this->module('Bridge', [$user]);
        $catalog = $this->module('Catalog', [$bridge]);
        $this->reference('src/Module/User', 'src/Module/Catalog');
        $diagnosis = DiagnosticCollection::fromAnalysis($this->analyze())->diagnoses[0];
        self::assertStringContainsString(implode(' → ', [$user, $catalog, $bridge, $user]), $diagnosis->explanation);
        $steps = implode(' ', array_merge(...array_column($diagnosis->resolutions, 'steps')));
        self::assertStringNotContainsString($catalog.'::class', $steps);
        self::assertStringContainsString('Decide which module should depend on the other', $steps);
        foreach (['User', 'Catalog', 'Bridge'] as $name) {
            self::assertContains($this->source->directory.'/src/Module/'.$name.'/'.$name.'Module.php', $diagnosis->resolutions[0]->files);
        }
    }

    public function testExistingCycleKeepsItsDirectedOrderAndDoesNotChooseABadEdge(): void
    {
        $userClass = $this->module('User');
        $bridge = $this->module('Bridge', [$userClass]);
        $catalog = $this->module('Catalog', [$bridge]);
        $user = $this->module('User', [$catalog]);
        $analysis = $this->analyze();
        $diagnosis = DiagnosticCollection::fromAnalysis($analysis)->diagnoses[0];
        self::assertSame('declared_cycle', $diagnosis->basis);
        self::assertStringContainsString(implode(' → ', [$bridge, $user, $catalog, $bridge]), $diagnosis->explanation);
        self::assertStringContainsString('cannot decide which dependency', $diagnosis->explanation);
        self::assertCount(3, $diagnosis->descriptors);
        self::assertSame('option', $diagnosis->resolutions[0]->kind);
        self::assertSame('dependency.cycle', $analysis->findings[0]->rule);
    }

    public function testPrivateAccessDoesNotRecommendDeclarationAndNamesTheRealOwner(): void
    {
        $this->project('Shop');
        $secret = $this->module('Secret', project: 'Shop');
        $user = $this->module('User');
        $this->project('Public', [$user]);
        $this->reference('src/Module/User', 'src/Project/Shop/Module/Secret');
        $analysis = $this->analyze(['projects' => ['shop' => ['hosts' => ['shop.test']], 'public' => ['hosts' => ['public.test']]]]);
        self::assertSame(['code.private'], array_column($analysis->findings, 'rule'));
        $diagnosis = DiagnosticCollection::fromAnalysis($analysis)->diagnoses[0];
        self::assertStringContainsString($this->source->namespace.'\Project\Shop\ShopProject', $diagnosis->explanation);
        self::assertStringContainsString('Declaring a dependency does not grant access', $diagnosis->explanation);
        self::assertStringNotContainsString($secret.'::class', implode(' ', $diagnosis->resolutions[0]->steps));
    }

    public function testSameNamedPrivateDescriptorsKeepDistinctExecutableRecommendations(): void
    {
        $targets = [];
        foreach (['Shop', 'Office'] as $project) {
            $targets[$project] = $this->module('User', project: $project);
            $portrait = $this->module('Portrait', project: $project);
            $entity = $this->source->php('src/Project/'.$project.'/Module/User/Entity/User.php', 'final class User {}');
            $this->source->php('src/Project/'.$project.'/Module/Portrait/Consumer.php', 'final class Consumer { public \\'.$entity.' $user; }');
            $this->project($project, [$portrait, $targets[$project]]);
        }
        $config = ['projects' => ['shop' => ['hosts' => ['shop.test']], 'office' => ['hosts' => ['office.test']]]];
        $analysis = $this->analyze($config);
        self::assertSame(['code.undeclared_dependency', 'code.undeclared_dependency'], array_column($analysis->findings, 'rule'));
        $report = ArchitectureReport::fromAnalysis($analysis);
        $output = new BufferedOutput();
        (new ArchitectureRenderer($output, 90, all: true))->render($report->presentation);
        $text = preg_replace('/\s+/', '', $output->fetch()) ?? '';
        $markdown = (new ReportRenderer($report))->markdown('test', new \DateTimeImmutable());
        foreach ($targets as $project => $target) {
            self::assertStringContainsString('add\\'.$target.'::classtotheSillbeamModule', $text);
            self::assertStringContainsString('add '.str_replace('\\', '\\\\', '\\'.$target).'::class', $markdown);
            $this->module('Portrait', [$target], $project);
        }
        self::assertStringNotContainsString('addUser::class', $text);
        self::assertSame([], $this->freshRules($config));

        // The other Project's same-named User remains private, even with a familiar label.
        $other = $this->source->namespace.'\\Project\\Office\\Module\\User\\Entity\\User';
        $this->source->php('src/Project/Shop/Module/Portrait/CrossProject.php', 'final class CrossProject { public \\'.$other.' $user; }');
        self::assertSame(['code.private'], $this->freshRules($config));
    }

    /** @return iterable<string, array{string}> */
    public static function activationModes(): iterable
    {
        yield 'shared disabled' => ['shared'];
        yield 'private disabled' => ['private'];
        yield 'shared unassigned' => ['unassigned'];
    }

    #[DataProvider('activationModes')]
    public function testDisabledAndUnassignedModulesHaveDifferentAdviceAndScopedAddresses(string $mode): void
    {
        $private = 'private' === $mode;
        $catalog = $this->module('Catalog', project: $private ? 'Shop' : null);
        $user = $this->module('User', 'unassigned' === $mode ? [] : [$catalog], project: $private ? 'Shop' : null);
        $this->project('Shop', $private ? [] : [$user]);
        $prefix = $private ? 'src/Project/Shop/Module/' : 'src/Module/';
        $this->reference($prefix.'User', $prefix.'Catalog');
        $key = $private ? 'project:shop:catalog' : 'shared:catalog';
        $config = 'unassigned' === $mode ? [] : ['modules' => [$key => ['enabled' => false]]];
        $analysis = $this->analyze($config);
        $collection = DiagnosticCollection::fromAnalysis($analysis);
        $text = implode(' ', array_column($collection->diagnoses, 'explanation'));
        $resolutions = array_merge(...array_column($collection->diagnoses, 'resolutions'));
        if ('unassigned' === $mode) {
            self::assertStringContainsString('enabled but unassigned', $text);
            self::assertStringContainsString($this->source->directory.'/src/Project/Shop/ShopProject.php', implode(' ', array_merge(...array_column($resolutions, 'steps'))));
            self::assertStringNotContainsString('.enabled to true', implode(' ', array_merge(...array_column($resolutions, 'steps'))));
        } else {
            self::assertStringContainsString('disabled in the module configuration', $text);
            self::assertContains('sillbeam.modules["'.$key.'"].enabled', array_merge(...array_column($resolutions, 'configuration')));
            self::assertSame(2, $collection->findingCount());
            self::assertSame(1, $collection->diagnosisCount());
            self::assertCount(1, $resolutions);
            // Applying the stated override change is sufficient for these fixtures.
            self::assertSame([], $this->freshRules(['modules' => [$key => ['enabled' => true]]]));
        }
    }

    public function testDisabledPrivateModuleKeyUsesOwnerRatherThanRequestingProject(): void
    {
        $secret = $this->module('Secret', project: 'Shop');
        $this->project('Shop');
        $this->project('Other', [$secret]);
        $analysis = $this->analyze(['modules' => ['project:shop:secret' => ['enabled' => false]], 'projects' => ['shop' => ['hosts' => ['shop.test']], 'other' => ['hosts' => ['other.test']]]]);
        $collection = DiagnosticCollection::fromAnalysis($analysis);
        foreach ($collection->diagnoses as $diagnosis) {
            foreach ($diagnosis->resolutions as $resolution) {
                self::assertNotContains('sillbeam.modules["project:other:secret"].enabled', $resolution->configuration);
            }
        }
        $resolutions = array_merge(...array_column($collection->diagnoses, 'resolutions'));
        self::assertContains('sillbeam.modules["project:shop:secret"].enabled', array_merge(...array_column($resolutions, 'configuration')));
        $steps = implode(' ', array_merge(...array_column($resolutions, 'steps')));
        self::assertStringNotContainsString('set sillbeam.modules["project:shop:secret"].enabled to true', $steps);
        self::assertStringContainsString('enabling it does not permit this Project access', implode(' ', array_column($collection->diagnoses, 'explanation')));
        self::assertSame(['dependency.disabled', 'project.private_module'], array_column($analysis->findings, 'rule'));
    }

    public function testUnknownConfigurationAdviceUsesReportedAddressesWithoutGuessingFiles(): void
    {
        $this->module('User');
        $analysis = $this->analyze(['modules' => ['shared:missing' => ['enabled' => false]], 'projects' => ['missing' => ['hosts' => ['missing.test']]]]);
        $collection = DiagnosticCollection::fromAnalysis($analysis);
        $resolutions = array_merge(...array_column($collection->diagnoses, 'resolutions'));
        $addresses = array_merge(...array_column($resolutions, 'configuration'));
        self::assertContains('sillbeam.modules["shared:missing"]', $addresses);
        self::assertContains('sillbeam.projects["missing"]', $addresses);
        foreach ($resolutions as $resolution) {
            self::assertSame([], $resolution->files);
        }
        self::assertSame([], $this->freshRules());
    }

    public function testDuplicateProjectIdsAreOneProvenCauseWithAllDescriptors(): void
    {
        foreach (['Shop', 'Other', 'Third'] as $name) {
            $this->source->php('src/Project/'.$name.'/'.$name.'Project.php', '#[\\Sillbeam\\Core\\Project\\Attribute\\SillbeamProject(id: "same")] final class '.$name.'Project {}');
        }
        $collection = DiagnosticCollection::fromAnalysis($this->analyze());
        self::assertSame(2, $collection->findingCount());
        self::assertSame(1, $collection->diagnosisCount());
        self::assertSame('duplicate_project_id', $collection->diagnoses[0]->basis);
        self::assertCount(3, $collection->diagnoses[0]->resolutions[0]->files);
        self::assertSame([], $collection->diagnoses[0]->resolutions[0]->configuration);
    }

    public function testDuplicateHostsGroupByActualHostAndExposeBothConfigurationAddresses(): void
    {
        $this->project('Shop');
        $this->project('Other');
        $this->project('Third');
        $analysis = $this->analyze(['projects' => ['shop' => ['hosts' => ['same.test']], 'other' => ['hosts' => ['same.test']], 'third' => ['hosts' => ['same.test']]]]);
        $collection = DiagnosticCollection::fromAnalysis($analysis);
        self::assertSame(2, $collection->findingCount());
        self::assertSame(1, $collection->diagnosisCount());
        self::assertCount(1, $collection->diagnoses[0]->resolutions);
        $resolution = $collection->diagnoses[0]->resolutions[0];
        foreach (['shop', 'other', 'third'] as $id) {
            self::assertContains('sillbeam.projects["'.$id.'"].hosts', $resolution->configuration);
        }
        self::assertSame([], $this->freshRules(['projects' => ['shop' => ['hosts' => ['shop.test']], 'other' => ['hosts' => ['other.test']], 'third' => ['hosts' => ['third.test']]]]));
    }

    public function testDuplicateModuleIdsKeepAllKnownDescriptorsAndDoNotInventAUniqueOverride(): void
    {
        foreach (['User', 'Catalog', 'Bridge'] as $name) {
            $this->source->php('src/Module/'.$name.'/'.$name.'Module.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(id: "same")] final class '.$name.'Module {}');
        }
        $analysis = $this->analyze();
        $collection = DiagnosticCollection::fromAnalysis($analysis);
        self::assertSame(2, $collection->findingCount());
        self::assertSame(1, $collection->diagnosisCount());
        self::assertStringContainsString('Analysis incomplete', $collection->diagnoses[0]->explanation);
        self::assertCount(3, $collection->diagnoses[0]->resolutions[0]->files);
        self::assertSame([], $collection->diagnoses[0]->resolutions[0]->configuration);
    }

    public function testFatalParseRecoveryIsConcreteAndDoesNotInventFollowUpFindings(): void
    {
        $this->source->write('src/Module/Broken/Broken.php', '<?php class {');
        $analysis = $this->analyze();
        $collection = DiagnosticCollection::fromAnalysis($analysis);
        self::assertSame(1, $collection->findingCount());
        self::assertStringContainsString('Analysis incomplete', $collection->diagnoses[0]->explanation);
        self::assertSame([$this->source->directory.'/src/Module/Broken/Broken.php'], $collection->diagnoses[0]->resolutions[0]->files);
        self::assertStringContainsString($analysis->findings[0]->message, $collection->diagnoses[0]->explanation);
        $this->source->php('src/Module/Broken/Broken.php', 'final class Broken {}');
        self::assertSame([], $this->freshRules());
    }

    public function testUnknownApplicationClassAdviceIsBoundedAndCanBeCheckedAfterRestoringTheClass(): void
    {
        $this->module('User');
        $missing = $this->source->namespace.'\Module\User\Missing';
        $this->source->php('src/Module/User/Consumer.php', 'final class Consumer { public \\'.$missing.' $missing; }');
        $analysis = $this->analyze();
        self::assertSame(['code.unknown_class'], array_column($analysis->findings, 'rule'));
        $diagnosis = DiagnosticCollection::fromAnalysis($analysis)->diagnoses[0];
        self::assertStringContainsString($missing, $diagnosis->explanation);
        self::assertStringContainsString('does not validate all PHP autoloading, external Composer types or Symfony autowiring', $diagnosis->explanation);
        $this->source->php('src/Module/User/Missing.php', 'final class Missing {}');
        self::assertSame([], $this->freshRules());
    }

    /** @param list<class-string> $dependencies
     * @return class-string
     */
    private function module(string $name, array $dependencies = [], ?string $project = null): string
    {
        $path = null === $project ? 'src/Module/' : 'src/Project/'.$project.'/Module/';
        $values = implode(', ', array_map(static fn (string $class): string => '\\'.$class.'::class', $dependencies));

        return $this->source->php($path.$name.'/'.$name.'Module.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(dependencies: ['.$values.'])] final class '.$name.'Module {}');
    }

    /** @param list<class-string> $modules */
    private function project(string $name, array $modules = []): void
    {
        $values = implode(', ', array_map(static fn (string $class): string => '\\'.$class.'::class', $modules));
        $this->source->php('src/Project/'.$name.'/'.$name.'Project.php', '#[\Sillbeam\Core\Project\Attribute\SillbeamProject(modules: ['.$values.'])] final class '.$name.'Project {}');
    }

    private function reference(string $source, string $target): void
    {
        $port = $this->source->php($target.'/Port.php', 'interface Port {}');
        $this->source->php($source.'/Consumer.php', 'final class Consumer { public \\'.$port.' $port; }');
    }

    /** @param array<string, mixed> $config */
    private function analyze(array $config = []): ArchitectureAnalysisResult
    {
        return (new ArchitectureAnalyzer())->analyze(new DiscoveryOptions($this->source->directory.'/src', $this->source->namespace.'\\'), $config);
    }

    /** @param array<string, mixed> $config
     * @return list<string>
     */
    private function freshRules(array $config = []): array
    {
        $script = <<<'PHP'
require $argv[1];
$loader = new Composer\Autoload\ClassLoader();
$loader->addPsr4($argv[3].'\\', $argv[2].'/src');
$loader->register(true);
$result = (new Sillbeam\Core\Architecture\ArchitectureAnalyzer())->analyze(new Sillbeam\Core\Architecture\DiscoveryOptions($argv[2].'/src', $argv[3].'\\'), json_decode($argv[4], true, flags: JSON_THROW_ON_ERROR));
echo json_encode(array_column($result->findings, 'rule'), JSON_THROW_ON_ERROR);
PHP;
        $stdout = $this->source->directory.'/analysis.json';
        $stderr = $this->source->directory.'/analysis.err';
        $process = proc_open([\PHP_BINARY, '-r', $script, \dirname(__DIR__, 2).'/vendor/autoload.php', $this->source->directory, $this->source->namespace, json_encode($config, \JSON_THROW_ON_ERROR)], [\STDIN, ['file', $stdout, 'w'], ['file', $stderr, 'w']], $pipes);
        self::assertIsResource($process);
        self::assertSame(0, proc_close($process), (string) file_get_contents($stderr));
        $contents = file_get_contents($stdout);
        self::assertIsString($contents);
        $rules = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($rules);
        self::assertTrue(array_is_list($rules));
        foreach ($rules as $rule) {
            self::assertIsString($rule);
        }

        return $rules;
    }
}
