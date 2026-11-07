<?php

declare(strict_types=1);

namespace Sillbeam\Core\Api;

/** @internal Canonical, locale-independent architecture fingerprint. */
final class ArchitectureFingerprint
{
    public const int VERSION = 1;

    /** @param array<string, CoreDataObject> $modules
     * @param array<string, CoreDataObject>                                          $projects
     * @param array{module: list<CoreDataObject>, application: list<CoreDataObject>} $references
     */
    public static function hash(string $status, array $modules, array $projects, array $references): string
    {
        $moduleData = array_map(static function (CoreDataObject $module): array {
            $fields = $module->fields;
            unset($fields['description']);

            return $fields;
        }, $modules);
        $projectData = array_map(static function (CoreDataObject $project): array {
            $fields = $project->fields;
            unset($fields['description']);
            // The first configured host is the canonical host.
            $hosts = $fields['hosts'];
            if (!\is_array($hosts)) {
                throw new \LogicException('Expected a Project host list.');
            }
            $fields['hosts'] = new CoreDataObject(['canonical' => $hosts[0] ?? null, 'hosts' => $hosts]);

            return $fields;
        }, $projects);
        $relationships = [];
        foreach ($references as $kind => $rows) {
            foreach ($rows as $row) {
                $fields = $row->fields;
                unset($fields['id'], $fields['source_line']);
                $relationships[$kind][serialize($fields)] = $fields;
            }
        }
        $data = ['status' => $status, 'modules' => $moduleData, 'projects' => $projectData, 'references' => array_map('array_values', $relationships)];

        return 'sha256:v'.self::VERSION.':'.hash('sha256', json_encode(self::normalize($data), \JSON_THROW_ON_ERROR));
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof CoreDataObject) {
            $value = $value->fields;
        }
        if (!\is_array($value)) {
            return $value;
        }
        $value = array_map(self::normalize(...), $value);
        if (array_is_list($value)) {
            $keys = array_map(static fn (mixed $item): string => json_encode($item, \JSON_THROW_ON_ERROR), $value);
            array_multisort($keys, \SORT_STRING, $value);
        } else {
            ksort($value, \SORT_STRING);
        }

        return $value;
    }
}
