<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture;

use Sillbeam\Core\Architecture\Console\ArchitecturePresentation;
use Sillbeam\Core\Architecture\Console\ModuleInspection;
use Sillbeam\Core\I18n\CoreError;
use Sillbeam\Core\I18n\CoreMessage;

/**
 * Scalar architecture report shared by text, JSON and Markdown command output.
 *
 * Schema version 1 represents valid, violations or unavailable status. Presentation data
 * is a separate internal view and is never serialized by toJson().
 *
 * @api
 */
final readonly class ArchitectureReport
{
    /**
     * Stores a schema-versioned scalar report; callers normally use fromAnalysis() or unavailable().
     *
     * @param array<string, mixed> $data         schema-version-1 report data including status, findings and module/Project catalogs
     * @param array<string, mixed> $presentation internal scalar view, never serialized to JSON
     */
    public function __construct(public array $data, /** @internal Scalar view used only by Core renderers. */ public array $presentation = [])
    {
    }

    /**
     * Builds a stable schema-version-1 report, retaining available evidence on invalid/incomplete analysis.
     *
     * Unknown activation is represented as null rather than an inactive module.
     *
     * @param array<string, mixed> $config     resolved configuration used to retain overrides and host evidence on incomplete results
     * @param string|null          $projectDir base directory for human-readable presentation paths
     */
    public static function fromAnalysis(ArchitectureAnalysisResult $analysis, array $config = [], ?string $projectDir = null): self
    {
        $graph = $analysis->architecture;
        $catalog = $graph->modules ?? $analysis->discovery->modules ?? [];
        $projects = $graph->projects ?? $analysis->discovery->projects ?? [];
        ksort($catalog);
        ksort($projects);
        $active = array_fill_keys($graph?->usedModules() ?? [], true);
        $modules = [];
        foreach ($catalog as $module) {
            $projectId = null === $module->projectClass ? null : ($projects[$module->projectClass]->id ?? null);
            $override = $config['modules'][$module->key($projectId)] ?? (null === $module->projectClass ? ($config['modules'][$module->id] ?? []) : []);
            $enabled = null !== $graph ? $module->enabled : ($analysis->isComplete() ? (bool) ($override['enabled'] ?? true) : null);
            $used = null === $graph ? null : isset($active[$module->class]);
            $references = $analysis->references[$module->class] ?? [];
            $modules[] = [
                'id' => $module->id, 'class' => $module->class, 'scope' => $module->isPrivate() ? 'private' : 'shared',
                'project' => $module->projectClass, 'enabled' => $enabled, 'active' => $used,
                'inactive_reason' => null === $used ? 'unknown' : ($used ? null : ($enabled ? 'unassigned' : 'disabled')),
                'directory' => $module->directory, 'descriptor' => $module->descriptorFile,
                'declared_dependencies' => $module->dependencies,
                'observed_dependencies' => array_values(array_unique(array_column($references, 'module'))),
                'references' => $references,
                'activation_reasons' => $graph?->activationReasons($module->class) ?? [],
                'controllers' => $module->controllerDirectories, 'entities' => $module->entityDirectories,
            ];
            $applicationReferences = $analysis->applicationReferences[$module->class] ?? [];
            if ([] !== $applicationReferences) {
                $index = array_key_last($modules);
                $modules[$index]['application_dependencies'] = array_values(array_unique(array_column($applicationReferences, 'class')));
                $modules[$index]['application_references'] = $applicationReferences;
            }
        }
        $projectData = [];
        foreach ($projects as $project) {
            $data = get_object_vars($project);
            if (null === $graph) {
                $data['modules'] = null;
                $data['hosts'] = array_map(static fn ($host): string => (string) $host, $config['projects'][$project->id]['hosts'] ?? []);
            }
            $projectData[] = $data;
        }

        return new self([
            'schema_version' => 1,
            'status' => !$analysis->isComplete() ? 'unavailable' : ($analysis->isValid() ? 'valid' : 'violations'),
            'valid' => $analysis->isValid(), 'complete' => $analysis->isComplete(),
            'findings' => array_map(static function (ArchitectureFinding $finding): array {
                $data = get_object_vars($finding);
                if (null !== ($suggestion = $finding->suggestion())) {
                    $data['context']['suggestion'] = $suggestion;
                }

                return $data;
            }, $analysis->findings),
            'modules' => $modules, 'projects' => $projectData,
        ], ArchitecturePresentation::fromAnalysis($analysis, $projectDir) + ['module_inspection' => ModuleInspection::metadata($analysis, $config)]);
    }

    /**
     * Converts an execution failure into a fatal report.execution finding with unavailable status.
     */
    public static function unavailable(\Throwable $error): self
    {
        return self::fromAnalysis(new ArchitectureAnalysisResult([
            ArchitectureFinding::fromMessage('report.execution', new CoreMessage('finding.report.execution', ['error' => CoreError::message($error)]), ['exception' => $error::class], 'fatal'),
        ]));
    }

    /**
     * Evaluates a report provider while suppressing incidental output.
     *
     * Temporarily buffers output and installs an error handler: enabled warnings become
     * exceptions, notices/deprecations are suppressed. Any Throwable becomes an unavailable
     * report. Restores the previous handler and closes added buffers in all cases.
     *
     * @param self|\Closure(): self $provider an existing report or a closure evaluated once inside the guarded output/error scope
     */
    public static function collect(self|\Closure $provider): self
    {
        $level = ob_get_level();
        ob_start();
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            if ($severity & (\E_DEPRECATED | \E_USER_DEPRECATED | \E_NOTICE | \E_USER_NOTICE)) {
                return true;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            return $provider instanceof \Closure ? $provider() : $provider;
        } catch (\Throwable $error) {
            return self::unavailable($error);
        } finally {
            restore_error_handler();
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    /**
     * Maps report status to CLI success (0), architecture violations (1) or unavailable (2).
     */
    public function exitCode(): int
    {
        return match ($this->data['status']) {
            'valid' => 0,
            'violations' => 1,
            default => 2,
        };
    }

    /**
     * Serializes only schema data as pretty JSON, keeping finding context as an object.
     *
     * Invalid UTF-8 is substituted; the internal presentation view is omitted.
     *
     * @throws \JsonException if report data contains values that cannot be encoded
     */
    public function toJson(): string
    {
        $data = $this->data;
        foreach ($data['findings'] as &$finding) {
            $finding['context'] = (object) $finding['context'];
        }
        unset($finding);

        return json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
