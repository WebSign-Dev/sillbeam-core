<?php

declare(strict_types=1);

namespace Sillbeam\Core\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sillbeam\Core\Console\Application;
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

    public function testFingerprintIsStableAcrossRunsAndChangesWithArchitecture(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->php('src/Module/A/AModule.php', '#[\Sillbeam\Core\Module\Attribute\SillbeamModule(id: "a")] final class AModule {}');
            [$code, $first] = $this->snapshot($source);
            [, $second] = $this->snapshot($source);
            self::assertSame(0, $code);
            self::assertSame(1, $first['metadata']['fingerprint_version']);
            self::assertMatchesRegularExpression('/^sha256:v1:[0-9a-f]{64}$/', $first['metadata']['architecture_fingerprint']);
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
            $source->write('src/Module/A/Assets/logo.txt', 'example');
            $source->write('src/Excluded/Broken.php', '<?php class {');
            $source->write('config/packages/sillbeam.yaml', 'sillbeam: { discovery: { excludes: ["Excluded", "Excluded/**"] } }');
            [$exit, $inventory] = $this->runCommand($source, ['command' => 'sillbeam:inspect:sources', '--format' => 'json']);
            self::assertSame(0, $exit);
            self::assertArrayNotHasKey('analysis', $inventory);
            $files = array_column($inventory['files'], null, 'path');
            self::assertSame('module', $files['src/Module/A/Assets/logo.txt']['ownership']);
            self::assertArrayNotHasKey('src/Excluded/Broken.php', $files);
            self::assertNotEmpty($inventory['excluded_paths']);
            self::assertSame(\count($files), $inventory['summary']['file_count']);
        } finally {
            $source->remove();
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
