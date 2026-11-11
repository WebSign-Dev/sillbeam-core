<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture;

use Sillbeam\Core\Architecture\Console\ArchitecturePresentation;
use Sillbeam\Core\Architecture\Console\ModuleInspection;
use Sillbeam\Core\I18n\CoreError;
use Sillbeam\Core\I18n\CoreMessage;
use Sillbeam\Core\I18n\CoreTranslator;

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
        return self::fromLocalizedAnalysis($analysis, $config, $projectDir, new CoreTranslator());
    }

    /**
     * @internal the translator only affects presentation, never schema data
     *
     * @param array<string, mixed> $config
     */
    public static function fromLocalizedAnalysis(ArchitectureAnalysisResult $analysis, array $config, ?string $projectDir, CoreTranslator $translator, bool $compiled = false): self
    {
        $catalog = self::catalog($analysis, $config);
        $presentation = ArchitecturePresentation::fromAnalysis($analysis, $projectDir, $translator)
            + ['module_inspection' => ModuleInspection::metadata($analysis, $config)];
        if ($compiled && ([] !== $analysis->findings || [] !== $analysis->applicationReferences)) {
            foreach (CoreTranslator::locales() as $locale) {
                if ($locale !== $translator->locale) {
                    $presentation['locales'][$locale] = ArchitecturePresentation::fromAnalysis($analysis, $projectDir, new CoreTranslator($locale));
                }
            }
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
            ...$catalog,
        ], $presentation);
    }

    /**
     * Shared scalar catalog from the validated graph and available discovery evidence.
     *
     * @internal
     *
     * @param array<string, mixed> $config
     *
     * @return array{modules: list<array<string, mixed>>, projects: list<array<string, mixed>>}
     */
    public static function catalog(ArchitectureAnalysisResult $analysis, array $config): array
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

        return ['modules' => $modules, 'projects' => $projectData];
    }

    /**
     * Converts an execution failure into a fatal report.execution finding with unavailable status.
     */
    public static function unavailable(\Throwable $error): self
    {
        return self::unavailableForLocale($error, new CoreTranslator());
    }

    private static function unavailableForLocale(\Throwable $error, CoreTranslator $translator): self
    {
        return self::fromLocalizedAnalysis(new ArchitectureAnalysisResult([
            ArchitectureFinding::fromMessage('report.execution', new CoreMessage('finding.report.execution', ['error' => CoreError::message($error)]), ['exception' => $error::class], 'fatal'),
        ]), [], null, $translator);
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
        return self::collectReport($provider, new CoreTranslator());
    }

    /** @param self|\Closure(): self $provider */
    private static function collectReport(self|\Closure $provider, CoreTranslator $translator): self
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
            return self::unavailableForLocale($error, $translator);
        } finally {
            restore_error_handler();
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    /** @internal
     * @param self|\Closure(CoreTranslator): self $provider
     */
    public static function collectLocalized(self|\Closure $provider, CoreTranslator $translator): self
    {
        $report = self::collectReport($provider instanceof \Closure ? static fn (): self => $provider($translator) : $provider, $translator);
        $presentation = $report->presentation['locales'][$translator->locale] ?? null;

        return null === $presentation ? $report : new self($report->data, $presentation + $report->presentation);
    }

    /**
     * @internal returns localized prose without changing the original finding data
     *
     * @param array<string, mixed> $finding
     */
    public function findingMessage(array $finding): string
    {
        return $this->presentation['finding_messages'][self::findingKey($finding)] ?? $finding['message'];
    }

    /**
     * @internal localizes only the human remediation hint in finding context
     *
     * @param array<string, mixed> $finding
     *
     * @return array<string, mixed>
     */
    public function findingContext(array $finding): array
    {
        $context = $finding['context'];
        $hint = $this->presentation['finding_hints'][self::findingKey($finding)] ?? null;
        if (null !== $hint && isset($context['suggestion'])) {
            $context['suggestion'] = $hint;
        }

        return $context;
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $finding
     */
    public static function findingKey(array $finding): string
    {
        $context = $finding['context'] ?? [];
        unset($context['suggestion']);
        ksort($context);

        return hash('sha256', serialize([$finding['rule'], $finding['message'], $finding['severity'] ?? null, $context]));
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
