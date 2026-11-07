<?php

declare(strict_types=1);

namespace Sillbeam\Core\Api;

use Sillbeam\Core\Architecture\ArchitectureAnalysisResult;
use Sillbeam\Core\Architecture\ArchitectureFinding;
use Sillbeam\Core\Architecture\Console\ArchitecturePresentation;
use Sillbeam\Core\Architecture\Diagnostic\Diagnosis;
use Sillbeam\Core\Architecture\Diagnostic\DiagnosticCollection;
use Sillbeam\Core\I18n\CoreTranslator;

/** @internal Projects existing diagnostic groups and their original evidence. */
final readonly class LiveDiagnostics
{
    /** @param \Closure(string): ?string $relativePath */
    public function __construct(private CoreTranslator $translator, private \Closure $relativePath)
    {
    }

    /** @return array{findings: list<CoreDataObject>, diagnoses: list<CoreDataObject>} */
    public function project(ArchitectureAnalysisResult $analysis): array
    {
        $collection = DiagnosticCollection::fromAnalysis($analysis, $this->translator);
        $findings = [];
        foreach ($collection->findings as $index => $finding) {
            $findings[] = new CoreDataObject([
                'index' => $index, 'rule' => $finding->rule, 'severity' => $finding->severity,
                'message' => $finding->message, 'context' => new CoreDataObject($finding->context),
                'location' => $this->location($finding),
                'diagnosis_id' => $collection->assignments[$index],
                'translations' => $this->translated([
                    'message' => $finding->localizedMessage($this->translator),
                    'hint' => $finding->suggestion($this->translator),
                ]),
            ]);
        }
        $keys = [];
        $counts = [];
        foreach ($collection->diagnoses as $diagnosis) {
            $key = $this->correlationKey($diagnosis);
            if (null !== $key) {
                $keys[$diagnosis->id] = $key;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        $diagnoses = [];
        $resolutionNumber = 0;
        foreach ($collection->diagnoses as $diagnosis) {
            $key = $keys[$diagnosis->id] ?? null;
            $diagnoses[] = $this->diagnosis($diagnosis, null !== $key && 1 === $counts[$key] ? $key : null, $resolutionNumber);
        }

        return ['findings' => $findings, 'diagnoses' => $diagnoses];
    }

    private function diagnosis(Diagnosis $diagnosis, ?string $key, int &$resolutionNumber): CoreDataObject
    {
        $details = [];
        $problems = [];
        $impacts = [];
        $cautions = [];
        foreach ($diagnosis->details as $offset => $detail) {
            $details[] = new CoreDataObject([
                'finding_index' => $diagnosis->findingIndexes[$offset],
                'caution' => $detail->caution,
                'translations' => $this->translated(['problem' => $detail->problem, 'impact' => $detail->impact]),
            ]);
            $problems[] = $detail->problem;
            $impacts[] = $detail->impact;
            if ($detail->caution) {
                $cautions[] = $detail->impact;
            }
        }
        $resolutions = [];
        foreach ($diagnosis->resolutions as $resolution) {
            $resolutions[] = new CoreDataObject([
                'id' => 'R'.(++$resolutionNumber),
                'kind' => $resolution->kind,
                'files' => array_values(array_unique($this->relativePaths($resolution->files))),
                'configuration' => array_values(array_unique($resolution->configuration)),
                'translations' => $this->translated(['steps' => $resolution->steps]),
            ]);
        }
        [$title, $category] = ArchitecturePresentation::heading($diagnosis->findings[0]->rule, \count($diagnosis->findings), $this->translator);

        return new CoreDataObject([
            'id' => $diagnosis->id,
            'correlation_key' => $key, 'correlation_version' => null === $key ? null : 1,
            'basis' => $diagnosis->basis,
            'severity' => array_any($diagnosis->findings, static fn (ArchitectureFinding $finding): bool => 'fatal' === $finding->severity) ? 'fatal' : 'error',
            'rule_codes' => array_values(array_unique(array_column($diagnosis->findings, 'rule'))),
            'finding_indexes' => $diagnosis->findingIndexes,
            'modules' => array_values(array_unique($diagnosis->modules)),
            'descriptors' => new CoreDataObject($this->relativePaths($diagnosis->descriptors)),
            'details' => $details,
            'translations' => $this->translated([
                'title' => $title, 'category' => $category,
                'problem' => implode(' ', array_unique($problems)),
                'impact' => implode(' ', array_unique($impacts)),
                'caution' => [] === $cautions ? null : implode(' ', array_unique($cautions)),
            ]),
            'resolutions' => $resolutions,
        ]);
    }

    /** @param array<array-key, string> $paths
     * @return array<array-key, string>
     */
    private function relativePaths(array $paths): array
    {
        return array_filter(array_map($this->relativePath, $paths), static fn (?string $path): bool => null !== $path);
    }

    private function location(ArchitectureFinding $finding): ?CoreDataObject
    {
        $file = $finding->context['file'] ?? null;
        $line = $finding->context['line'] ?? null;
        if (!\is_string($file) || !\is_int($line) || $line < 1 || !is_file($file) || null === ($path = ($this->relativePath)($file))) {
            return null;
        }

        return new CoreDataObject(['path' => $path, 'line' => $line, 'column' => null]);
    }

    private function correlationKey(Diagnosis $diagnosis): ?string
    {
        if ([] === $diagnosis->modules || !\in_array($diagnosis->basis, ['declared_cycle', 'declared_dependency', 'observed_reference', 'disabled_module'], true)) {
            return null;
        }
        $rules = array_values(array_unique(array_column($diagnosis->findings, 'rule')));
        sort($rules, \SORT_STRING);
        $modules = $diagnosis->modules;
        if ('declared_cycle' === $diagnosis->basis) {
            // Cycles have no distinguished start. Preserve their direction.
            array_pop($modules);
            if ([] === $modules) {
                return null;
            }
            $start = (int) array_search(min($modules), $modules, true);
            $modules = [...\array_slice($modules, $start), ...\array_slice($modules, 0, $start)];
        }

        return 'sha256:v1:'.hash('sha256', json_encode([$diagnosis->basis, $rules, $modules], \JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $fields */
    private function translated(array $fields): CoreDataObject
    {
        return new CoreDataObject([$this->translator->locale => new CoreDataObject($fields)]);
    }
}
