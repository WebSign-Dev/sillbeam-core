<?php

declare(strict_types=1);

namespace Sillbeam\Core\Api;

use Sillbeam\Core\Architecture\ArchitectureAnalysisResult;
use Sillbeam\Core\Architecture\ArchitectureReport;
use Sillbeam\Core\Architecture\Console\ModuleInspection;
use Sillbeam\Core\Architecture\SourceInventoryEvidence;
use Sillbeam\Core\I18n\CoreTranslator;
use Symfony\Component\HttpKernel\Kernel;

/** @internal Mutable projection state for one snapshot. */
final class CoreDataAssembler
{
    private readonly CoreTranslator $translator;
    private readonly SnapshotPaths $paths;
    /** @var array<string, CoreTranslator> */
    private readonly array $locales;

    /** @var array<string, true> */
    private array $omissions = [];

    public function __construct(private readonly CoreDataRequest $request, private readonly ?string $projectDir)
    {
        $this->translator = new CoreTranslator($request->locale ?? CoreTranslator::DEFAULT_LOCALE);
        $locales = CoreDataProfile::Analysis === $request->profile ? CoreTranslator::locales() : [$this->translator->locale];
        $this->locales = array_combine($locales, array_map(static fn (string $locale): CoreTranslator => new CoreTranslator($locale), $locales));
        $this->paths = new SnapshotPaths($projectDir);
    }

    public function assemble(
        ArchitectureAnalysisResult $result,
        ?CoreDataConfiguration $input,
        int $duration,
        string $analyzedAt,
        ?string $failure,
        ?SourceInventoryEvidence $evidence,
        ?SnapshotInputs $inputs,
        string $environment,
        ?string $kernelFile,
    ): CoreDataDocument {
        $config = $input->config ?? [];
        $catalog = ArchitectureReport::catalog($result, $config);
        $inspection = ModuleInspection::metadata($result, $config);
        $projects = $this->projects($catalog['projects']);
        $modules = $this->modules($result, $catalog['modules'], $projects, $inspection['configuration']);
        $references = $this->references($result, $modules);
        $diagnostics = null === $evidence
            ? (new LiveDiagnostics($this->locales[$this->translator->locale], $this->relative(...)))->project($result)
            : (new LiveDiagnostics(CoreTranslator::capture(), $this->relative(...), $this->locales))->project($result);
        $complete = null === $failure && $result->isComplete();
        $available = $complete || null !== $result->discovery;
        $status = !$complete ? 'unavailable' : ($result->isValid() ? 'valid' : 'violations');
        $completeness = [
            'architecture' => $available ? 'complete' : 'unavailable',
            'diagnostics' => $complete ? 'complete' : ([] === $result->findings ? 'unavailable' : 'partial'),
            'references' => $complete && $inspection['references_complete'] ? 'complete' : ([] === $result->references && [] === $result->applicationReferences ? 'unavailable' : 'partial'),
        ];
        $errors = [];
        if (null !== $failure) {
            $errors[] = new CoreDataObject([
                'code' => 'export.analysis_unavailable', 'domain' => 'architecture', 'severity' => 'fatal',
                'path' => null, 'context' => new CoreDataObject(['exception' => $failure]),
                'translations' => $this->message('api.analysis_unavailable'),
            ]);
        }
        foreach (array_keys($this->omissions) as $domain) {
            $completeness[$domain] = 'partial';
            $errors[] = new CoreDataObject([
                'code' => 'export.path_unavailable', 'domain' => $domain, 'severity' => 'error',
                'path' => null, 'context' => new CoreDataObject(),
                'translations' => $this->message('api.path_unavailable'),
            ]);
        }
        $graph = $inventory = $doctor = $fingerprint = null;
        if (null !== $evidence) {
            [$doctor, $completeness['doctor']] = SnapshotDoctor::collect($this->projectDir, $environment, $kernelFile, $this->locales);
            if ('complete' !== $completeness['doctor']) {
                $errors[] = $this->error('doctor.execution', 'doctor', null, 'api.doctor_unavailable');
            }

            $changed = $evidence->changed();
            $configurationChanged = $inputs?->changed() ?? false;
            if (null !== $input && null === $result->discovery && [] !== $evidence->files) {
                $evidence->failures[$input->options->root] = 'inventory.discovery';
            }
            $inventoryData = (new SourceInventory($this->relative(...)))->project($input?->options, $result->discovery, $evidence);
            $inventory = $inventoryData['data'];
            $completeness['source_inventory'] = $inventoryData['status'];
            foreach ($inventoryData['errors'] as $error) {
                $errors[] = $this->error($error['code'], 'source_inventory', $error['path'], 'api.'.$error['code']);
            }
            if ([] !== $changed || $configurationChanged) {
                foreach ($completeness as $domain => $state) {
                    if ('complete' === $state && ('doctor' !== $domain || $configurationChanged)) {
                        $completeness[$domain] = 'partial';
                    }
                }
                $errors[] = $this->error('export.input_changed', 'export', null, 'api.export.input_changed');
            }
            $graphComplete = 'complete' === $completeness['architecture'] && 'complete' === $completeness['references'];
            $graphState = null !== $result->architecture && $complete && $graphComplete ? 'validated'
                : ([] !== $modules || [] !== $projects || $complete ? 'evidence_only' : 'unavailable');
            $completeness['graph'] = $graphComplete ? 'complete' : ('unavailable' === $graphState ? 'unavailable' : 'partial');
            $graph = SnapshotGraph::project($modules, $projects, $references['module'], $graphState, 'complete' === $completeness['references'], $this->locales);
            if ($complete && $graphComplete && [] === $changed && !$configurationChanged) {
                $fingerprint = ArchitectureFingerprint::hash($status, $modules, $projects, $references);
            }
        }
        $hasEvidence = array_any($completeness, static fn (string $domain): bool => 'complete' === $domain || 'partial' === $domain);
        $export = match (true) {
            !$hasEvidence => 'unavailable',
            $complete && array_all($completeness, static fn (string $domain): bool => 'complete' === $domain) => 'complete',
            default => 'partial',
        };

        return new CoreDataDocument(
            $this->request,
            new CoreDataObject([
                'core_version' => CoreCapabilitiesProvider::CORE_VERSION,
                'analysis_run_id' => self::runId(),
                'analyzed_at' => $analyzedAt,
                'duration_ms' => $duration,
                'php_version' => \PHP_VERSION,
                'symfony_version' => Kernel::VERSION,
                'architecture_fingerprint' => $fingerprint,
                'fingerprint_version' => null === $fingerprint ? null : ArchitectureFingerprint::VERSION,
            ]),
            new CoreDataObject([
                'default_locale' => CoreTranslator::DEFAULT_LOCALE,
                'available_locales' => CoreTranslator::locales(),
                'included_locales' => array_keys($this->locales),
                'requested_locale' => $this->request->locale,
                'fallbacks' => array_map(static fn (array $fallback): CoreDataObject => new CoreDataObject($fallback), array_merge(...array_values(array_map(static fn (CoreTranslator $translator): array => $translator->fallbacks(), $this->locales)))),
            ]),
            new CoreDataObject(['status' => $status, 'valid' => 'valid' === $status, 'complete' => $complete]),
            new CoreDataObject(['status' => $export]),
            new CoreDataObject($completeness),
            new CoreDataObject([
                'module_count' => \count($modules), 'project_count' => \count($projects),
                'finding_count' => \count($diagnostics['findings']), 'diagnosis_count' => \count($diagnostics['diagnoses']),
                'module_reference_count' => \count($references['module']), 'application_reference_count' => \count($references['application']),
            ]),
            new CoreDataObject(['modules' => array_values($modules), 'projects' => array_values($projects)]),
            new CoreDataObject($diagnostics),
            new CoreDataObject($references),
            $errors,
            new CoreDataObject(),
            $graph,
            $inventory,
            $doctor,
        );
    }

    /** @param list<array<string, mixed>> $rows
     * @return array<class-string, CoreDataObject>
     */
    private function projects(array $rows): array
    {
        $projects = [];
        foreach ($rows as $row) {
            $paths = $this->catalogPaths($row);
            if (null === $paths) {
                continue;
            }
            $projects[$row['class']] = new CoreDataObject([
                ...$paths,
                'id' => $row['id'], 'class' => $row['class'], 'description' => $row['description'], 'namespace' => $row['namespace'],
                'declared_modules' => $this->unique($row['declaredModules']),
                'modules' => null === $row['modules'] ? null : $this->unique($row['modules']),
                'hosts' => $this->unique(array_filter($row['hosts'], static fn (string $host): bool => '' !== $host)),
            ]);
        }

        return $projects;
    }

    /** @param list<array<string, mixed>> $rows
     * @param array<class-string, CoreDataObject>       $projects
     * @param array<class-string, array<string, mixed>> $configuration
     *
     * @return array<class-string, CoreDataObject>
     */
    private function modules(ArchitectureAnalysisResult $result, array $rows, array $projects, array $configuration): array
    {
        $members = array_map(static fn (array $classes): array => array_fill_keys($classes, true), $result->architecture->projectModules ?? []);
        $definitions = $result->architecture->modules ?? $result->discovery->modules ?? [];
        $modules = [];
        foreach ($rows as $row) {
            $paths = $this->catalogPaths($row);
            if (null === $paths) {
                continue;
            }
            $class = $row['class'];
            $settings = $configuration[$class];
            $states = [];
            foreach (array_keys($projects) as $projectClass) {
                $states[] = new CoreDataObject([
                    'project_class' => $projectClass,
                    'state' => null === $result->architecture ? 'unknown' : (isset($members[$projectClass][$class]) ? 'active' : ($row['enabled'] ? 'unassigned' : 'disabled')),
                ]);
            }
            $modules[$class] = new CoreDataObject([
                ...$paths,
                'id' => $row['id'], 'class' => $class, 'description' => $definitions[$class]->description, 'namespace' => $definitions[$class]->namespace,
                'scope' => $row['scope'], 'project' => $row['project'], 'enabled' => $row['enabled'], 'active' => $row['active'],
                'inactive_reason' => $row['inactive_reason'],
                'declared_dependencies' => $this->unique($row['declared_dependencies']),
                'observed_dependencies' => $this->unique($row['observed_dependencies']),
                'activation_reasons' => null === $row['active'] ? null : $row['activation_reasons'],
                'configuration' => new CoreDataObject(['key' => $settings['key'], 'override_source' => $settings['source'], 'enabled_override' => $settings['enabled_override']]),
                'project_states' => $states,
            ]);
        }

        return $modules;
    }

    /** @param array<string, mixed> $row
     * @return array{directory: string, descriptor: string, controllers: list<string>, entities: list<string>}|null
     */
    private function catalogPaths(array $row): ?array
    {
        $directory = $this->relative($row['directory'], 'architecture');
        $descriptor = $this->relative($row['descriptor'] ?? $row['descriptorFile'], 'architecture');
        if (null === $directory || null === $descriptor) {
            return null;
        }

        return [
            'directory' => $directory, 'descriptor' => $descriptor,
            'controllers' => $this->pathList($row['controllers'] ?? $row['controllerDirectories']),
            'entities' => $this->pathList($row['entities'] ?? $row['entityDirectories']),
        ];
    }

    /** @param list<string> $paths
     * @return list<string>
     */
    private function pathList(array $paths): array
    {
        $relative = array_map(fn (string $path): ?string => $this->relative($path, 'architecture'), $paths);

        return $this->unique(array_filter($relative, static fn (?string $path): bool => null !== $path));
    }

    /** @param array<class-string, CoreDataObject> $modules
     * @return array{module: list<CoreDataObject>, application: list<CoreDataObject>}
     */
    private function references(ArchitectureAnalysisResult $result, array $modules): array
    {
        $definitions = $result->architecture->modules ?? $result->discovery->modules ?? [];
        $output = ['module' => [], 'application' => []];
        foreach (['module' => $result->references, 'application' => $result->applicationReferences] as $kind => $groups) {
            $rows = [];
            foreach ($groups as $source => $references) {
                foreach ($references as $reference) {
                    $file = $this->relative($reference['file'], 'references');
                    $target = $reference['module'] ?? null;
                    if (null === $file || $reference['line'] < 1 || !isset($modules[$source]) || (null !== $target && !isset($modules[$target]))) {
                        $this->omissions['references'] = true;
                        continue;
                    }
                    $row = ['source_module' => $source, 'target_class' => $reference['class'], 'source_file' => $file, 'source_line' => $reference['line']];
                    if (null !== $target) {
                        $row += [
                            'target_module' => $target,
                            'declared' => \in_array($target, $definitions[$source]->dependencies, true),
                            'observed' => true,
                        ];
                    } else {
                        $row += ['target_file' => isset($reference['target_file']) ? $this->relative($reference['target_file']) : null, 'classification' => 'informational'];
                    }
                    $rows[serialize($row)] = $row;
                }
            }
            ksort($rows, \SORT_STRING);
            foreach (array_values($rows) as $index => $row) {
                $output[$kind][] = new CoreDataObject(['id' => ('module' === $kind ? 'MR' : 'AR').($index + 1), ...$row]);
            }
        }

        return $output;
    }

    private function relative(string $path, ?string $domain = null): ?string
    {
        $relative = $this->paths->relative($path);
        if (null === $relative && null !== $domain) {
            $this->omissions[$domain] = true;
        }

        return $relative;
    }

    /** @param array<array-key, string> $values
     * @return list<string>
     */
    private function unique(array $values): array
    {
        return array_values(array_unique($values));
    }

    private function message(string $key): CoreDataObject
    {
        return new CoreDataObject(array_map(static fn (CoreTranslator $translator): CoreDataObject => new CoreDataObject(['message' => $translator->text($key)]), $this->locales));
    }

    private function error(string $code, string $domain, ?string $path, string $key): CoreDataObject
    {
        return new CoreDataObject([
            'code' => $code, 'domain' => $domain, 'severity' => 'error', 'path' => $path,
            'context' => new CoreDataObject(), 'translations' => $this->message($key),
        ]);
    }

    private static function runId(): string
    {
        $hex = bin2hex(random_bytes(16));
        $hex[12] = '4';
        $hex[16] = dechex((hexdec($hex[16]) & 3) | 8);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}
