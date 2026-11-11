<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture\Console;

use Sillbeam\Core\Architecture\ArchitectureReport;
use Sillbeam\Core\I18n\CoreTranslator;

/**
 * Presentation of existing report evidence shared by SVG and Mermaid.
 * Filters inspect direct edge endpoints once; no dependency traversal or analysis.
 * Descriptor identities and node IDs remain stable across filtered views.
 *
 * @internal
 */
final readonly class GraphView
{
    /** @param array<string, array<string, mixed>> $nodes
     * @param list<array{source: string, target: string, observed: bool}> $edges
     * @param array<string, string>                                       $groups
     * @param list<string>                                                $notes
     */
    private function __construct(public array $nodes, public array $edges, public array $groups, public array $notes)
    {
    }

    /** @throws \InvalidArgumentException for missing or ambiguous selectors */
    public static function fromReport(ArchitectureReport $report, ?string $module = null, ?string $project = null, CoreTranslator $translator = new CoreTranslator()): self
    {
        $inspection = new ModuleInspection($report);
        $context = null === $project ? null : $inspection->selectProject($project);
        $focus = null === $module ? null : $inspection->select($module, $project)['module']['class'];
        $modules = $report->data['modules'];
        $projects = $report->data['projects'];
        usort($modules, static fn (array $a, array $b): int => strcmp($a['class'], $b['class']));
        usort($projects, static fn (array $a, array $b): int => strcmp($a['class'], $b['class']));
        $projectMap = array_column($projects, null, 'class');
        $counts = array_count_values(array_map(static fn (array $item): string => strtolower($item['id']), $modules));
        $projectCounts = array_count_values(array_map(static fn (array $item): string => strtolower($item['id']), $projects));
        $groups = ['shared_scope' => $translator->text('cli.graph.label.shared')];
        $projectGroups = [];
        foreach ($projects as $index => $item) {
            $group = \sprintf('project_scope_%04d', $index + 1);
            $projectGroups[$item['class']] = $group;
            $groups[$group] = $translator->text('cli.graph.label.groups', ['id' => $item['id'], 'class' => $projectCounts[strtolower($item['id'])] > 1 ? ' | '.$item['class'] : '', 'state' => $translator->text(null === $item['modules'] ? 'cli.label.unknown' : 'cli.label.validated')]);
        }
        $groups['unknown_owner'] = $translator->text('cli.graph.label.missing_owner_group');
        $cycles = [];
        foreach ($report->data['findings'] as $finding) {
            if ('dependency.cycle' === $finding['rule']) {
                foreach (explode(', ', (string) ($finding['context']['cycle'] ?? '')) as $class) {
                    $cycles[$class] = true;
                }
            }
        }
        $nodes = $eligible = $edges = $missing = [];
        foreach ($modules as $index => $item) {
            $class = $item['class'];
            $eligible[$class] = null === $context || 'shared' === $item['scope'] || $item['project'] === $context['class'];
            $active = null === $context ? $item['active'] : (null === $context['modules'] ? null : \in_array($class, $context['modules'], true));
            $state = null === $active || null === $item['enabled'] ? 'unknown' : (!$item['enabled'] ? 'disabled' : ($active ? 'active' : 'unassigned'));
            $label = $item['id'];
            if ($counts[strtolower($item['id'])] > 1) {
                $ownerLabel = 'shared' === $item['scope'] ? $translator->text('cli.graph.label.scope_shared') : $translator->text('cli.graph.label.private_owner', ['project' => $projectMap[$item['project']]['id'] ?? $item['project'] ?? $translator->text('cli.graph.label.unknown_owner')]);
                $collisions = array_values(array_filter($modules, static fn (array $candidate): bool => 0 === strcasecmp($candidate['id'], $item['id'])
                    && $candidate['scope'] === $item['scope'] && $candidate['project'] === $item['project']));
                $label .= "\n".$ownerLabel;
                if ('private' === $item['scope'] && isset($projectMap[$item['project']]) && $projectCounts[strtolower($projectMap[$item['project']]['id'])] > 1) {
                    $label .= ' | '.$item['project'];
                }
                if (\count($collisions) > 1) {
                    $label .= ' | '.self::descriptorLabel($class, array_column($collisions, 'class'));
                }
            }
            $label .= "\n".$translator->text('cli.graph.label.node_state', ['state' => $translator->text('cli.label.'.$state)]).(false === $item['enabled'] && 'disabled' !== $state ? $translator->text('cli.graph.label.disabled') : '').(isset($cycles[$class]) ? $translator->text('cli.graph.label.cycle') : '');
            if ('shared' === $item['scope'] && null === $context && [] !== $projects) {
                $memberships = [];
                foreach ($projects as $owner) {
                    if (null !== $owner['modules'] && \in_array($class, $owner['modules'], true)) {
                        $memberships[] = $owner['id'].($projectCounts[strtolower($owner['id'])] > 1 ? ' ('.$owner['class'].')' : '');
                    }
                }
                if ([] !== $memberships) {
                    $label .= $translator->text('cli.graph.label.projects', ['projects' => implode(', ', $memberships)]);
                }
            }
            $nodes[$class] = ['id' => \sprintf('m%04d', $index + 1), 'label' => $label, 'class' => $class,
                'group' => 'shared' === $item['scope'] ? 'shared_scope' : ($projectGroups[$item['project']] ?? 'unknown_owner'),
                'state' => $state, 'disabled' => false === $item['enabled'], 'cycle' => isset($cycles[$class]), 'external' => !$eligible[$class]];
            $declared = array_unique($item['declared_dependencies']);
            foreach ([false, true] as $observed) {
                $targets = $observed ? array_diff(array_unique($item['observed_dependencies']), $declared) : $declared;
                foreach ($targets as $target) {
                    $edges[] = ['source' => $class, 'target' => $target, 'observed' => $observed];
                    $missing[$target] = true;
                }
            }
        }
        $unresolved = array_diff(array_keys($missing), array_keys($nodes));
        sort($unresolved, \SORT_STRING);
        foreach ($unresolved as $index => $class) {
            $nodes[$class] = ['id' => \sprintf('u%04d', $index + 1), 'label' => $translator->text('cli.graph.label.unresolved_node', ['class' => $class]), 'class' => $class,
                'group' => 'unresolved', 'state' => 'unknown', 'disabled' => false, 'cycle' => false, 'external' => null !== $context];
        }
        $groups['unresolved'] = $translator->text('cli.graph.label.unresolved');
        $edges = array_values(array_filter($edges, static fn (array $edge): bool => null !== $focus
            ? $edge['source'] === $focus || $edge['target'] === $focus
            : (null === $context || ($eligible[$edge['source']] ?? false) || ($eligible[$edge['target']] ?? false))));
        $visible = null === $focus ? array_filter($eligible) : [$focus => true];
        foreach ($edges as $edge) {
            $visible[$edge['source']] = $visible[$edge['target']] = true;
        }
        $nodes = array_intersect_key($nodes, $visible);
        usort($edges, static fn (array $a, array $b): int => [$a['source'], $a['target'], $a['observed']] <=> [$b['source'], $b['target'], $b['observed']]);
        $complete = $report->data['complete'] && ($report->presentation['module_inspection']['references_complete'] ?? false);
        $notes = [$translator->text('cli.graph.label.analysis', ['status' => $translator->text('cli.label.'.$report->data['status']), 'evidence' => $translator->text($report->data['valid'] ? 'cli.graph.label.validated' : 'cli.graph.label.evidence')]),
            $report->data['complete'] ? $translator->text('cli.graph.label.complete') : $translator->text('cli.graph.label.incomplete'),
            $complete ? $translator->text('cli.graph.label.refs_complete') : $translator->text('cli.graph.label.refs_incomplete')];
        if (null !== $context) {
            $notes[] = $translator->text('cli.graph.label.project_context', ['id' => $context['id']]);
        }
        if (null !== $focus) {
            $notes[] = $translator->text('cli.graph.label.focus', ['class' => $focus]);
        }
        if ([] === $nodes) {
            $notes[] = $translator->text('cli.graph.label.catalog_empty');
        }
        foreach ($projects as $item) {
            if ([] === array_filter($nodes, static fn (array $node): bool => $node['group'] === $projectGroups[$item['class']])) {
                if (null === $context || $context['class'] === $item['class']) {
                    $notes[] = $translator->text('cli.graph.label.membership', ['id' => $item['id'], 'state' => $translator->text(null === $item['modules'] ? 'cli.label.unknown' : 'cli.label.validated')]);
                }
            }
        }
        $notes[] = $translator->text('cli.graph.label.legend');
        $notes[] = $translator->text('cli.graph.label.states');
        $findings = $report->data['findings'];
        usort($findings, static fn (array $a, array $b): int => [$a['rule'], $a['message']] <=> [$b['rule'], $b['message']]);
        foreach ($findings as $finding) {
            $notes[] = $finding['rule'].': '.$report->findingMessage($finding);
        }

        return new self($nodes, $edges, $groups, $notes);
    }

    /** Shortest unique descriptor suffix, used only for collisions in the same ownership scope.
     * @param list<string> $classes
     */
    private static function descriptorLabel(string $class, array $classes): string
    {
        $parts = explode('\\', $class);
        for ($length = 1; $length <= \count($parts); ++$length) {
            $suffix = implode('\\', \array_slice($parts, -$length));
            $matches = array_filter($classes, static fn (string $candidate): bool => $candidate === $suffix || str_ends_with($candidate, '\\'.$suffix));
            if (1 === \count($matches)) {
                return $suffix;
            }
        }

        return $class;
    }
}
