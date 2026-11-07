<?php

declare(strict_types=1);

namespace Sillbeam\Core\Api;

use Sillbeam\Core\I18n\CoreTranslator;

/** @internal Graph data projected from the emitted catalog and reference evidence. */
final class SnapshotGraph
{
    /**
     * @param array<class-string, CoreDataObject> $modules
     * @param array<class-string, CoreDataObject> $projects
     * @param list<CoreDataObject>                $references
     * @param array<string, CoreTranslator>       $locales
     */
    public static function project(array $modules, array $projects, array $references, string $state, bool $referencesComplete, array $locales): CoreDataObject
    {
        $nodes = $edges = $groups = [];
        $group = static function (string $type, ?string $owner, string $label, bool $resource = false) use (&$groups, $locales): string {
            $id = 'GR'.(\count($groups) + 1);
            $translations = [];
            foreach ($locales as $locale => $translator) {
                $translations[$locale] = new CoreDataObject(['label' => $resource ? $translator->text($label) : $label]);
            }
            $groups[] = new CoreDataObject(['id' => $id, 'type' => $type, 'owner' => $owner, 'translations' => new CoreDataObject($translations)]);

            return $id;
        };
        $node = static function (string $class, string $type, ?string $scope, ?string $owner, string $status, ?string $groupId) use (&$nodes): string {
            if (!isset($nodes[$class])) {
                $nodes[$class] = new CoreDataObject(['id' => $class, 'type' => $type, 'scope' => $scope, 'project' => $owner, 'state' => $status, 'group_id' => $groupId]);
            }

            return $class;
        };
        $projectGroups = [];
        foreach ($projects as $class => $project) {
            $projectGroups[$class] = $group('project', $class, $class);
            $node($class, 'project', null, null, 'validated' === $state ? 'active' : 'unknown', $projectGroups[$class]);
        }
        $shared = $unresolved = null;
        foreach ($modules as $class => $module) {
            $data = $module->fields;
            $owner = isset($data['project']) ? self::string($data['project']) : null;
            $groupId = null === $owner ? ($shared ??= $group('shared_scope', null, 'api.graph.shared', true)) : ($projectGroups[$owner] ?? null);
            $moduleState = 'validated' !== $state ? 'unknown' : (true === $data['active'] ? 'active' : self::string($data['inactive_reason'] ?? 'unknown'));
            $node($class, 'module', self::string($data['scope']), $owner, $moduleState, $groupId);
        }
        $edge = static function (string $source, string $target, string $type, ?bool $declared, ?bool $observed, array $ids = []) use (&$edges): void {
            $edges[] = new CoreDataObject([
                'id' => 'GE'.(\count($edges) + 1), 'source' => $source, 'target' => $target, 'type' => $type,
                'declared' => $declared, 'observed' => $observed, 'reference_ids' => $ids,
            ]);
        };
        $targets = static function (string $class) use (&$nodes, &$unresolved, $node, $group): string {
            return isset($nodes[$class]) ? $class : $node($class, 'unresolved', null, null, 'unknown', $unresolved ??= $group('unresolved', null, 'api.graph.unresolved', true));
        };
        $observations = [];
        foreach ($references as $reference) {
            $data = $reference->fields;
            $observations[self::string($data['source_module'])][self::string($data['target_module'])][] = self::string($data['id']);
        }
        foreach ($modules as $class => $module) {
            $declared = self::strings($module->fields['declared_dependencies']);
            foreach ($declared as $target) {
                $ids = $observations[$class][$target] ?? [];
                $edge($targets($class), $targets($target), 'declared_dependency', true, [] !== $ids ? true : ($referencesComplete ? false : null), $ids);
            }
            foreach ($observations[$class] ?? [] as $target => $ids) {
                $edge($targets($class), $targets($target), 'observed_module_reference', \in_array($target, $declared, true), true, $ids);
            }
        }
        foreach ($projects as $class => $project) {
            $owned = array_keys(array_filter($modules, static fn (CoreDataObject $module): bool => $module->fields['project'] === $class));
            $members = null === $project->fields['modules'] ? [...self::strings($project->fields['declared_modules']), ...$owned] : self::strings($project->fields['modules']);
            foreach (array_unique($members) as $member) {
                $edge($targets($class), $targets($member), 'project_membership', null, null);
            }
        }

        return new CoreDataObject(['validation_state' => $state, 'nodes' => array_values($nodes), 'edges' => $edges, 'groups' => $groups]);
    }

    private static function string(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \LogicException('Expected a graph identifier or state.');
        }

        return $value;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new \LogicException('Expected a list of graph identifiers.');
        }

        return array_map(self::string(...), $value);
    }
}
