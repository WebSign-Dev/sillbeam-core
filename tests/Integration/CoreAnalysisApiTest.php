<?php

declare(strict_types=1);

namespace Sillbeam\Core\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sillbeam\Core\Api\CoreDataJsonSerializer;
use Sillbeam\Core\Api\CoreDataProvider;
use Sillbeam\Core\Api\CoreDataRequest;
use Sillbeam\Core\Console\Application;
use Sillbeam\Core\Console\KernelArchitectureReporter;
use Sillbeam\Core\I18n\CoreTranslator;
use Sillbeam\Core\Tests\Support\SourceWorkspace;
use Sillbeam\Core\Tests\Support\TestApplicationKernel;
use Symfony\Component\Console\Tester\ApplicationTester;

final class CoreAnalysisApiTest extends TestCase
{
    public function testAnalysisKeepsOutsideParseFailuresInInventory(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->write('config/packages/framework.yaml', 'framework: { secret: test }');
            $source->php('src/Module/A/AModule.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(id: "a")] final class AModule {}');
            $source->php('src/Service/Types.php', 'interface Contract {} trait Behavior {} enum State { case Open; }');
            $source->write('src/Service/Broken.php', '<?php class {');
            $source->write('src/Service/readme.txt', 'fixture');
            $app = new Application(new TestApplicationKernel($source));
            $app->setAutoExit(false);
            $tester = new ApplicationTester($app);
            $exit = $tester->run(['command' => 'sillbeam:api', '--profile' => 'analysis'], ['capture_stderr_separately' => true]);
            self::assertSame(3, $exit, $tester->getErrorOutput());
            $data = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame('valid', $data['analysis']['status']);
            self::assertSame('partial', $data['completeness']['source_inventory']);
            self::assertSame([], $data['diagnostics']['findings']);
            self::assertSame(['en', 'de'], $data['i18n']['included_locales']);
            self::assertSame('validated', $data['graph']['validation_state']);
            $files = array_column($data['source_inventory']['files'], null, 'path');
            self::assertSame('failed', $files['src/Service/Broken.php']['parse_status']);
            self::assertSame('application', $files['src/Service/readme.txt']['ownership']);
            self::assertSame('not_parsed', $files['src/Service/readme.txt']['parse_status']);
            self::assertSame(['interface', 'trait', 'enum'], array_column($files['src/Service/Types.php']['symbols'], 'type'));
            self::assertSame(['en', 'de'], array_keys($data['doctor']['checks'][0]['translations']));
        } finally {
            $source->remove();
        }
    }

    public function testGraphLinksEvidenceAndProjectsWithoutClaimingInvalidActivation(): void
    {
        $source = new SourceWorkspace();
        try {
            $ns = $source->namespace;
            $billing = $source->php('src/Module/Billing/BillingModule.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(id: "billing")] final class BillingModule {}');
            $catalog = $source->php('src/Module/Catalog/CatalogModule.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(id: "catalog")] final class CatalogModule {}');
            $project = $source->php('src/Project/Shop/ShopProject.php', '#[\Sillbeam\Core\Project\Attribute\SillbeamProject(id: "shop", modules: [\\'.$billing.'::class, \\'.$catalog.'::class])] final class ShopProject {}');
            $private = $source->php('src/Project/Shop/Internal/InternalModule.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(id: "internal")] final class InternalModule {}');
            $source->php('src/Module/Catalog/Item.php', 'final class Item {}');
            $source->php('src/Module/Billing/Consumer.php', 'final class Consumer { public \\'.$ns.'\\Module\\Catalog\\Item $item; }');

            [$code, $data] = $this->snapshot($source);
            self::assertSame(1, $code);
            self::assertSame('violations', $data['analysis']['status']);
            self::assertSame('evidence_only', $data['graph']['validation_state']);
            $nodes = array_column($data['graph']['nodes'], null, 'id');
            self::assertSame($project, $nodes[$private]['project']);
            self::assertSame('private', $nodes[$private]['scope']);
            self::assertSame(['unknown'], array_values(array_unique(array_column($nodes, 'state'))));
            $references = array_column($data['references']['module'], null, 'id');
            foreach ($data['graph']['edges'] as $edge) {
                self::assertArrayHasKey($edge['source'], $nodes);
                self::assertArrayHasKey($edge['target'], $nodes);
                foreach ($edge['reference_ids'] as $id) {
                    self::assertSame($edge['source'], $references[$id]['source_module']);
                    self::assertSame($edge['target'], $references[$id]['target_module']);
                }
            }
            $diagnosis = $data['diagnostics']['diagnoses'][0];
            foreach ([$diagnosis, $diagnosis['details'][0], $diagnosis['resolutions'][0], $data['diagnostics']['findings'][0]] as $row) {
                self::assertSame(['en', 'de'], array_keys($row['translations']));
                self::assertNotSame($row['translations']['en'], $row['translations']['de']);
            }
            $files = array_column($data['source_inventory']['files'], null, 'path');
            self::assertSame($project, $files['src/Project/Shop/Internal/InternalModule.php']['project_class']);
            self::assertSame($private, $files['src/Project/Shop/Internal/InternalModule.php']['module_class']);
        } finally {
            $source->remove();
        }
    }

    public function testUnreadableOutsideDirectoryDoesNotBlockLiveOrBecomeAFinding(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->php('src/Module/A/AModule.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(id: "a")] final class AModule {}');
            $source->write('src/Outside/Hidden.php', '<?php class Hidden {}');
            chmod($source->directory.'/src/Outside', 0000);
            if (is_readable($source->directory.'/src/Outside')) {
                self::markTestSkipped('The process can read permission-restricted directories.');
            }
            [$liveExit, $live] = $this->runCommand($source, ['command' => 'sillbeam:api', '--profile' => 'live']);
            self::assertSame(0, $liveExit);
            self::assertSame([], $live['diagnostics']['findings']);
            [$analysisExit, $analysis] = $this->snapshot($source);
            self::assertSame(3, $analysisExit);
            self::assertSame('valid', $analysis['analysis']['status']);
            self::assertSame('partial', $analysis['completeness']['source_inventory']);
            self::assertContains('inventory.read', array_column($analysis['errors'], 'code'));
        } finally {
            chmod($source->directory.'/src/Outside', 0700);
            $source->remove();
        }
    }

    public function testMissingGermanResourceIsReportedInLiveAndAnalysis(): void
    {
        $source = new SourceWorkspace();
        $catalogs = new \ReflectionProperty(CoreTranslator::class, 'loaded');
        $previous = $catalogs->getValue();
        try {
            $translator = new CoreTranslator('de');
            $translator->text('finding.source.parse', ['path' => 'test', 'error' => 'test']);
            $loaded = $catalogs->getValue();
            unset($loaded['de']['finding.source.parse']);
            $catalogs->setValue(null, $loaded);
            $source->write('src/Module/A/Broken.php', '<?php class {');
            foreach (['live', 'analysis'] as $profile) {
                $arguments = ['command' => 'sillbeam:api', '--profile' => $profile];
                if ('live' === $profile) {
                    $arguments['--locale'] = 'de';
                }
                [$exit, $data] = $this->runCommand($source, $arguments);
                self::assertSame(2, $exit);
                $finding = $data['diagnostics']['findings'][0];
                self::assertSame($finding['message'], $finding['translations']['de']['message']);
                self::assertContains(['resource_key' => 'finding.source.parse', 'locale' => 'de', 'source_locale' => 'en'], $data['i18n']['fallbacks']);
            }
        } finally {
            $catalogs->setValue(null, $previous);
            $source->remove();
        }
    }

    public function testDeclaredAndObservedDependenciesShareOneEdge(): void
    {
        $source = new SourceWorkspace();
        try {
            $b = $source->php('src/Module/B/BModule.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(id: "b")] final class BModule {}');
            $c = $source->php('src/Module/C/CModule.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(id: "c")] final class CModule {}');
            $a = $source->php('src/Module/A/AModule.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(id: "a", dependencies: [\\'.$b.'::class, \\'.$c.'::class])] final class AModule {}');
            $target = $source->php('src/Module/B/Target.php', 'final class Target {}');
            foreach (['First', 'Second'] as $class) {
                $source->php('src/Module/A/'.$class.'.php', 'final class '.$class.' { public \\'.$target.' $target; }');
            }
            [$exit, $data] = $this->snapshot($source);
            self::assertSame(0, $exit);
            self::assertSame('validated', $data['graph']['validation_state']);
            $edges = $data['graph']['edges'];
            self::assertCount(2, $edges);
            self::assertSame([$a, $a], array_column($edges, 'source'));
            $byTarget = array_column($edges, null, 'target');
            self::assertSame('declared_dependency', $byTarget[$b]['type']);
            self::assertTrue($byTarget[$b]['declared']);
            self::assertTrue($byTarget[$b]['observed']);
            self::assertCount(2, $byTarget[$b]['reference_ids']);
            self::assertSame(array_column($data['references']['module'], 'id'), $byTarget[$b]['reference_ids']);
            self::assertTrue($byTarget[$c]['declared']);
            self::assertFalse($byTarget[$c]['observed']);
            self::assertSame([], $byTarget[$c]['reference_ids']);
        } finally {
            $source->remove();
        }
    }

    public function testMissingApplicationSourceRootMakesInventoryUnavailable(): void
    {
        $source = new SourceWorkspace();
        try {
            [$presentExit, $present] = $this->snapshot($source);
            self::assertSame(0, $presentExit);
            self::assertSame('complete', $present['completeness']['source_inventory']);
            $source->write('config/packages/sillbeam.yaml', 'sillbeam: { discovery: { namespace: '.$source->namespace.' } }');
            rmdir($source->directory.'/src');
            [$exit, $data] = $this->snapshot($source);
            self::assertSame(3, $exit);
            self::assertSame('valid', $data['analysis']['status']);
            self::assertSame('unavailable', $data['completeness']['source_inventory']);
            self::assertContains('inventory.root_missing', array_column($data['errors'], 'code'));
            [$inventoryExit, $inventory] = $this->runCommand($source, ['command' => 'sillbeam:inspect:sources', '--format' => 'json']);
            self::assertSame(2, $inventoryExit);
            self::assertSame([], $inventory['files']);
        } finally {
            $source->remove();
        }
    }

    public function testMissingExplicitRootPreservesAvailableInventory(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->write('src/Note.txt', 'available');
            $options = new \Sillbeam\Core\Architecture\DiscoveryOptions(
                $source->directory.'/src',
                $source->namespace.'\\',
                [new \Sillbeam\Core\Architecture\DiscoveryRoot($source->directory.'/missing', $source->namespace.'\\Missing\\')],
            );
            $provider = new CoreDataProvider(new \Sillbeam\Core\Api\CoreDataConfiguration($options, $source->directory));
            $inventory = $provider->inspectSources();
            self::assertSame('partial', $inventory['status']);
            self::assertSame([['code' => 'inventory.root_missing', 'path' => null]], $inventory['errors']);
            $files = $inventory['data']->fields['files'];
            self::assertIsArray($files);
            self::assertCount(1, $files);
        } finally {
            $source->remove();
        }
    }

    public function testUnknownDeclaredTargetsHaveNodesAndNoInventedReferences(): void
    {
        $source = new SourceWorkspace();
        try {
            $module = $source->php('src/Module/A/AModule.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(id: "a", dependencies: [\Missing\Descriptor::class])] final class AModule {}');
            [$exit, $data] = $this->snapshot($source);
            self::assertSame(1, $exit);
            $nodes = array_column($data['graph']['nodes'], null, 'id');
            self::assertSame('unresolved', $nodes['Missing\Descriptor']['type']);
            $edge = $data['graph']['edges'][0];
            self::assertSame($module, $edge['source']);
            self::assertSame('Missing\Descriptor', $edge['target']);
            self::assertTrue($edge['declared']);
            self::assertFalse($edge['observed']);
            self::assertSame([], $edge['reference_ids']);
        } finally {
            $source->remove();
        }
    }

    public function testPublicPhpProviderAndSerializerMatchTheCliProjection(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->php('src/Module/A/AModule.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(id: "a", description: "Eigener Text")] final class AModule {}');
            [$exit, $cli] = $this->snapshot($source);
            self::assertSame(0, $exit);
            $kernel = new TestApplicationKernel($source);
            $configuration = (new KernelArchitectureReporter())->configuration($kernel);
            $provider = new CoreDataProvider($configuration, environment: $kernel->getEnvironment(), kernelFile: (new \ReflectionClass($kernel))->getFileName() ?: null);
            $document = $provider->collect(CoreDataRequest::fromProfile('analysis'));
            $php = json_decode((new CoreDataJsonSerializer())->serialize($document), true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame('Eigener Text', $php['architecture']['modules'][0]['description']);
            self::assertNotSame($cli['metadata']['analysis_run_id'], $php['metadata']['analysis_run_id']);
            foreach (['analysis_run_id', 'analyzed_at', 'duration_ms'] as $key) {
                unset($cli['metadata'][$key], $php['metadata'][$key]);
            }
            self::assertSame($cli, $php);
        } finally {
            $source->remove();
        }
    }

    public function testFingerprintIsStableAcrossRunsAndChangesWithArchitecture(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->php('src/Module/A/AModule.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(id: "a")] final class AModule {}');
            [$code, $first] = $this->snapshot($source);
            [, $second] = $this->snapshot($source);
            self::assertSame(0, $code);
            self::assertSame(2, $first['metadata']['fingerprint_version']);
            self::assertSame(\Sillbeam\Core\Api\CoreCapabilitiesProvider::coreVersion(), $first['metadata']['core_version']);
            self::assertMatchesRegularExpression('/^sha256:v2:[0-9a-f]{64}$/', $first['metadata']['architecture_fingerprint']);
            self::assertSame($first['metadata']['architecture_fingerprint'], $second['metadata']['architecture_fingerprint']);
            self::assertNotSame($first['metadata']['analysis_run_id'], $second['metadata']['analysis_run_id']);
            $source->write('config/packages/sillbeam.yaml', 'sillbeam: { modules: { a: { enabled: false } } }');
            [, $third] = $this->snapshot($source);
            self::assertNotSame($first['metadata']['architecture_fingerprint'], $third['metadata']['architecture_fingerprint']);
            self::assertFalse($third['architecture']['modules'][0]['enabled']);
        } finally {
            $source->remove();
        }
    }

    public function testFingerprintDistinguishesFindingsButIgnoresLineShifts(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->php('src/Module/A/AModule.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(id: "a")] final class AModule {}');
            $path = 'src/Module/A/Consumer.php';
            $source->php($path, 'final class Consumer {}');
            [, $valid] = $this->snapshot($source);
            $source->php($path, 'final class Consumer { public MissingOne $value; }');
            [, $first] = $this->snapshot($source);
            $source->php($path, "\n\n".'final class Consumer { public MissingOne $value; }');
            [, $shifted] = $this->snapshot($source);
            $source->php($path, 'final class Consumer { public MissingTwo $value; }');
            [, $second] = $this->snapshot($source);
            $source->php($path, 'final class Consumer {}');
            [, $restored] = $this->snapshot($source);
            $hash = static fn (array $snapshot): string => $snapshot['metadata']['architecture_fingerprint'];
            self::assertSame('code.unknown_class', $first['diagnostics']['findings'][0]['rule']);
            self::assertSame('code.unknown_class', $second['diagnostics']['findings'][0]['rule']);
            self::assertNotSame($hash($valid), $hash($first));
            self::assertSame($hash($first), $hash($shifted));
            self::assertNotSame($hash($first), $hash($second));
            self::assertSame($hash($valid), $hash($restored));
        } finally {
            $source->remove();
        }
    }

    public function testInputChangeDuringDiscoveryDegradesSnapshot(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->write('src/marker.txt', 'before');
            $source->php('src/Module/A/AModule.php', 'file_put_contents(dirname(__DIR__, 2)."/marker.txt", "after"); #[\Sillbeam\Core\Module\Attribute\SillbeamModule(id: "a")] final class AModule {}');
            [$code, $data] = $this->snapshot($source);
            self::assertSame(3, $code);
            self::assertSame('valid', $data['analysis']['status']);
            self::assertSame('partial', $data['export']['status']);
            self::assertSame('evidence_only', $data['graph']['validation_state']);
            self::assertNull($data['metadata']['architecture_fingerprint']);
            self::assertContains('export.input_changed', array_column($data['errors'], 'code'));
        } finally {
            $source->remove();
        }
    }

    public function testStandaloneInventoryDoesNotRunArchitectureRules(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->php('src/Module/A/AModule.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(id: "a", dependencies: [\Missing\Module::class])] final class AModule {}');
            $source->php('src/Module/A/AModule.php', 'file_put_contents(__DIR__."/ran.txt", "ran"); #[\Sillbeam\Core\Module\Attribute\SillbeamModule(id: "a", dependencies: [\Missing\Module::class])] final class AModule {}');
            $source->write('src/Module/A/Assets/logo.txt', 'example');
            $source->write('src/Excluded/Broken.php', '<?php class {');
            $source->write('config/packages/sillbeam.yaml', 'sillbeam: { discovery: { excludes: ["Excluded", "Excluded/**"] } }');
            [$exit, $inventory] = $this->runCommand($source, ['command' => 'sillbeam:inspect:sources', '--format' => 'json']);
            self::assertSame(0, $exit);
            self::assertArrayNotHasKey('analysis', $inventory);
            self::assertFileDoesNotExist($source->directory.'/src/Module/A/ran.txt');
            $files = array_column($inventory['files'], null, 'path');
            self::assertSame('module', $files['src/Module/A/Assets/logo.txt']['ownership']);
            self::assertArrayNotHasKey('src/Excluded/Broken.php', $files);
            self::assertNotEmpty($inventory['excluded_paths']);
            self::assertSame(\count($files), $inventory['summary']['file_count']);
        } finally {
            $source->remove();
        }
    }

    public function testInventoryDoesNotFollowSourceLinksOutsideAllowedRoots(): void
    {
        $source = new SourceWorkspace();
        $external = new SourceWorkspace();
        try {
            $external->write('src/Secret.php', '<?php class {');
            $source->write('src/Service/Local.php', '<?php class Local {}');
            symlink($external->directory.'/src/Secret.php', $source->directory.'/src/Service/External.php');
            symlink($source->directory.'/src/Service/Local.php', $source->directory.'/src/Service/Alias.php');
            [$exit, $data] = $this->snapshot($source);
            self::assertSame(3, $exit);
            self::assertSame('valid', $data['analysis']['status']);
            self::assertSame([], $data['diagnostics']['findings']);
            self::assertSame(['src/Service/Alias.php', 'src/Service/External.php'], $data['source_inventory']['excluded_paths']);
            self::assertSame(['src/Service/Local.php'], array_column($data['source_inventory']['files'], 'path'));
            self::assertSame('inventory.link', $data['errors'][0]['code']);
            self::assertSame('src/Service/External.php', $data['errors'][0]['path']);
        } finally {
            $source->remove();
            $external->remove();
        }
    }

    public function testDoctorPreservesRepeatedCheckIdsWhenArchitectureIsUnavailable(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->write('config/routes/invalid_first.yaml', 'first');
            $source->write('config/routes/invalid_second.yaml', 'second');
            $source->write('config/services.yaml', 'services: broken');
            [$exit, $data] = $this->snapshot($source);
            self::assertSame(2, $exit);
            $checks = $data['doctor']['checks'];
            $ids = array_column($checks, 'id');
            self::assertGreaterThanOrEqual(2, \count(array_filter($ids, static fn (string $id): bool => 'configuration.files' === $id)));
            self::assertSame(array_keys($checks), array_column($checks, 'index'));
            self::assertSame('complete', $data['completeness']['doctor']);
            self::assertSame('partial', $data['export']['status']);
            self::assertSame(2, $data['doctor']['exit_code']);
        } finally {
            $source->remove();
        }
    }

    /** @return array{int, array<string, mixed>} */
    private function snapshot(SourceWorkspace $source): array
    {
        return $this->runCommand($source, ['command' => 'sillbeam:api', '--profile' => 'analysis']);
    }

    /** @param array<string, string> $arguments
     * @return array{int, array<string, mixed>}
     */
    private function runCommand(SourceWorkspace $source, array $arguments): array
    {
        $source->write('config/packages/framework.yaml', 'framework: { secret: test }');
        $application = new Application(new TestApplicationKernel($source));
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $exit = $tester->run($arguments, ['capture_stderr_separately' => true]);
        self::assertNotSame('', $tester->getDisplay(), $tester->getErrorOutput());

        return [$exit, json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR)];
    }
}
