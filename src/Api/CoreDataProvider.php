<?php

declare(strict_types=1);

namespace Sillbeam\Core\Api;

use Sillbeam\Core\Architecture\ArchitectureAnalysisResult;
use Sillbeam\Core\Architecture\ArchitectureAnalyzer;
use Sillbeam\Core\Architecture\ArchitectureDiscovery;
use Sillbeam\Core\Architecture\SourceInspector;
use Sillbeam\Core\Architecture\SourceInventoryEvidence;

/** @api Collects a document from a single run of Core's existing analyzer. */
final readonly class CoreDataProvider implements CoreDataProviderInterface
{
    /**
     * @param CoreDataConfiguration|\Closure(): CoreDataConfiguration $configuration
     * @param string|\Closure(): string|null $projectDir
     */
    public function __construct(
        private CoreDataConfiguration|\Closure $configuration,
        private string|\Closure|null $projectDir = null,
        private string $environment = 'prod',
        private ?string $kernelFile = null,
    ) {
    }

    /** @return list<CoreDataProfile> */
    public static function supportedProfiles(): array
    {
        return CoreDataProfile::cases();
    }

    public function collect(CoreDataRequest $request): CoreDataDocument
    {
        $analysis = CoreDataProfile::Analysis === $request->profile;
        $inventory = $analysis ? new SourceInventoryEvidence() : null;
        $projectDir = null;
        $inputs = null;
        $input = null;
        $started = null;
        $failure = null;
        try {
            if ($analysis) {
                $projectDir = $this->projectDir instanceof \Closure ? ($this->projectDir)() : $this->projectDir;
                $projectDir ??= $this->configuration instanceof CoreDataConfiguration ? $this->configuration->projectDir : null;
                $inputs = null === $projectDir ? null : new SnapshotInputs($projectDir, $this->kernelFile);
            }
            $input = $this->resolveConfiguration();
            if ($analysis && null === $inputs) {
                $inputs = new SnapshotInputs($input->projectDir, $this->kernelFile);
            }
            $started = hrtime(true);
            $result = (new ArchitectureAnalyzer(new SourceInspector(limitParseFailuresToDiscoveryRoots: true, inventory: $inventory)))->analyze($input->options, $input->config);
        } catch (\Throwable $error) {
            $failure = $error::class;
            $result = new ArchitectureAnalysisResult();
        }
        $duration = null === $started ? 0 : (int) ((hrtime(true) - $started) / 1_000_000);
        $analyzedAt = gmdate('Y-m-d\TH:i:s\Z');

        $projectDir ??= $input?->projectDir;

        return (new CoreDataAssembler($request, $projectDir))->assemble($result, $input, $duration, $analyzedAt, $failure, $inventory, $inputs, $this->environment, $this->kernelFile);
    }

    /** @return array{data: CoreDataObject, status: string, errors: list<array{code: string, path: ?string}>} */
    public function inspectSources(): array
    {
        $input = $this->resolveConfiguration();
        $evidence = new SourceInventoryEvidence();
        $inspector = new SourceInspector(limitParseFailuresToDiscoveryRoots: true, inventory: $evidence, inventoryOnly: true);
        $discovery = null;
        try {
            $discovery = (new ArchitectureDiscovery($inspector))->discover($input->options);
        } catch (\Throwable) {
            $evidence->failures[$input->options->root] = 'inventory.discovery';
        }
        foreach ($evidence->changed() as $path) {
            $evidence->failures[$path] = 'export.input_changed';
        }

        return (new SourceInventory((new SnapshotPaths($input->projectDir))->relative(...)))
            ->project($input->options, $discovery, $evidence);
    }

    private function resolveConfiguration(): CoreDataConfiguration
    {
        return $this->configuration instanceof \Closure ? ($this->configuration)() : $this->configuration;
    }
}
