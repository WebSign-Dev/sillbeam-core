<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture\Console;

use Sillbeam\Core\Architecture\ArchitectureAnalysisResult;
use Sillbeam\Core\Architecture\ArchitectureReport;
use Sillbeam\Core\Architecture\Diagnostic\DiagnosticContext;
use Sillbeam\Core\I18n\CoreError;

/**
 * Queries the existing report catalog, direct edges and findings for one module.
 *
 * No discovery, dependency traversal, compilation or new validation takes place.
 * Actual activation is read only from the validated report graph, not diagnostic candidates.
 *
 * @internal
 */
final readonly class ModuleInspection
{
    public function __construct(private ArchitectureReport $report)
    {
    }

    /**
     * Retains scalar provenance omitted from public architecture JSON.
     *
     * Configuration identity is resolved by the existing diagnostic context. A
     * missing reference-map entry is not proof that no references exist.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function metadata(ArchitectureAnalysisResult $analysis, array $config): array
    {
        $context = DiagnosticContext::fromAnalysis($analysis);
        $settings = [];
        $overrides = $config['modules'] ?? [];
        foreach ($context->modules as $class => $module) {
            $key = $context->moduleConfigurationKey($class);
            $source = null;
            if (null !== $key) {
                $source = isset($overrides[$key]) ? $key : (null === $module->projectClass && isset($overrides[$module->id]) ? $module->id : null);
            }
            $enabledOverride = null === $source ? null : ($overrides[$source]['enabled'] ?? null);
            $settings[$class] = [
                'key' => $key,
                'source' => $source,
                'enabled_override' => \is_bool($enabledOverride) ? $enabledOverride : null,
                'references_complete' => $analysis->isComplete() && \array_key_exists($class, $analysis->references),
            ];
        }

        return [
            'configuration' => $settings,
            'references_complete' => $analysis->isComplete() && [] === array_diff(array_keys($context->modules), array_keys($analysis->references)),
        ];
    }

    /**
     * Selects an exact ID/class (case-insensitive), optionally in a Project context.
     *
     * Shared modules remain selectable when unassigned; private modules require
     * matching ownership. Ambiguity is never resolved by preferring one scope.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException if the module or Project is missing/ambiguous
     */
    public function select(string $identifier, ?string $projectSelector = null): array
    {
        $projects = $this->report->data['projects'];
        $project = null === $projectSelector ? null : $this->selectProject($projectSelector);
        $matches = array_values(array_filter($this->report->data['modules'], fn (array $module): bool => $this->matches($module, $identifier) && (null === $project || 'shared' === $module['scope'] || $module['project'] === $project['class'])));
        if ([] === $matches) {
            $unavailable = !$this->report->data['complete'];
            if ($unavailable || null === $project) {
                throw CoreError::create(\InvalidArgumentException::class, $unavailable ? 'cli.inspect.catalog_unknown' : 'cli.inspect.module_unknown', ['id' => $identifier]);
            }
            throw CoreError::create(\InvalidArgumentException::class, 'cli.inspect.project_module_unknown', ['id' => $identifier, 'project' => $project['id']]);
        }
        if (1 !== \count($matches)) {
            $candidates = array_map(static fn (array $module): string => $module['class'].' ('.$module['scope'].', owner: '.($module['project'] ?? 'none').')', $matches);
            sort($candidates, \SORT_STRING);
            throw CoreError::create(\InvalidArgumentException::class, 'cli.inspect.ambiguous_module', ['id' => $identifier, 'candidates' => implode('; ', $candidates)]);
        }
        $module = $matches[0];
        $active = $module['active'];
        if (null !== $project) {
            $active = null === $project['modules'] ? null : \in_array($module['class'], $project['modules'], true);
        }
        $state = self::state($module['enabled'], $active);
        $owner = null;
        $projectStates = [];
        foreach ($projects as $candidate) {
            if ($module['project'] === $candidate['class']) {
                $owner = $candidate;
            }
            if ('private' === $module['scope'] && $module['project'] !== $candidate['class']) {
                continue;
            }
            $membership = null === $candidate['modules'] ? null : \in_array($module['class'], $candidate['modules'], true);
            $projectStates[] = ['id' => $candidate['id'], 'class' => $candidate['class'], 'state' => self::state($module['enabled'], $membership)];
        }
        $incoming = [];
        $incomingReferences = [];
        foreach ($this->report->data['modules'] as $source) {
            if (\in_array($module['class'], $source['declared_dependencies'], true)) {
                $incoming[] = $source['class'];
            }
            foreach ($source['references'] as $reference) {
                if ($module['class'] === $reference['module']) {
                    $incomingReferences[] = ['source' => $source['class'], 'reference' => $reference];
                }
            }
        }
        sort($incoming, \SORT_STRING);
        $related = [];
        $blockers = [];
        foreach ($this->report->data['findings'] as $finding) {
            if ($this->relevant($finding, $module, $project ?? $owner)) {
                $related[] = $finding;
            } elseif ('fatal' === $finding['severity']) {
                $blockers[] = $finding;
            }
        }
        $metadata = $this->report->presentation['module_inspection'] ?? [];

        return [
            'module' => $module, 'owner' => $owner, 'project' => $project,
            'active' => $active, 'state' => $state, 'project_states' => $projectStates,
            'configuration' => $metadata['configuration'][$module['class']] ?? ['key' => null, 'source' => 'unknown', 'enabled_override' => null, 'references_complete' => false],
            'incoming' => array_values(array_unique($incoming)), 'incoming_references' => $incomingReferences,
            'references_complete' => $metadata['references_complete'] ?? false,
            'findings' => $related, 'blockers' => $blockers,
        ];
    }

    /**
     * Resolves the same Project identity for inspection and graph context selection.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException if the Project is missing or ambiguous
     */
    public function selectProject(string $identifier): array
    {
        $matches = array_values(array_filter($this->report->data['projects'], fn (array $candidate): bool => $this->matches($candidate, $identifier)));
        if (1 !== \count($matches)) {
            $candidates = array_column($matches, 'class');
            sort($candidates, \SORT_STRING);
            throw CoreError::create(\InvalidArgumentException::class, [] === $matches ? 'cli.inspect.project_unknown' : 'cli.inspect.project_ambiguous', ['id' => $identifier, 'candidates' => implode('; ', $candidates)]);
        }

        return $matches[0];
    }

    private static function state(?bool $enabled, ?bool $active): string
    {
        return null === $active || null === $enabled ? 'unknown' : (!$enabled ? 'disabled' : ($active ? 'active' : 'unassigned'));
    }

    /** @param array<string, mixed> $candidate */
    private function matches(array $candidate, string $identifier): bool
    {
        return 0 === strcasecmp($candidate['id'], $identifier) || 0 === strcasecmp($candidate['class'], ltrim($identifier, '\\'));
    }

    /** Filters existing evidence; in particular cycle membership is not recalculated.
     * @param array<string, mixed>      $finding
     * @param array<string, mixed>      $module
     * @param array<string, mixed>|null $project
     */
    private function relevant(array $finding, array $module, ?array $project): bool
    {
        $context = $finding['context'];
        foreach (['module', 'target', 'class'] as $key) {
            if (($context[$key] ?? null) === $module['class']) {
                return true;
            }
        }
        if ('dependency.disabled' === $finding['rule'] && \in_array($context['module'] ?? null, $module['declared_dependencies'], true)) {
            return true;
        }
        if ('dependency.cycle' === $finding['rule'] && \in_array($module['class'], explode(', ', (string) ($context['cycle'] ?? '')), true)) {
            return true;
        }
        $file = $context['file'] ?? null;
        if (\is_string($file) && ($file === $module['descriptor'] || str_starts_with($file, rtrim($module['directory'], '/').'/'))) {
            return true;
        }

        return null !== $project && ($context['project'] ?? null) === $project['class']
            && (str_starts_with($finding['rule'], 'project.host_') || 'project.duplicate_id' === $finding['rule']);
    }
}
