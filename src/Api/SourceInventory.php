<?php

declare(strict_types=1);

namespace Sillbeam\Core\Api;

use Sillbeam\Core\Architecture\DiscoveryOptions;
use Sillbeam\Core\Architecture\DiscoveryResult;
use Sillbeam\Core\Architecture\DiscoveryRoot;
use Sillbeam\Core\Architecture\FilesystemPath;
use Sillbeam\Core\Architecture\SourceInventoryEvidence;

/** @internal Projects the shared source observations into the v1 inventory. */
final readonly class SourceInventory
{
    /** @param \Closure(string): ?string $relative */
    public function __construct(private \Closure $relative)
    {
    }

    /** @return array{data: CoreDataObject, status: string, errors: list<array{code: string, path: ?string}>} */
    public function project(?DiscoveryOptions $options, ?DiscoveryResult $discovery, SourceInventoryEvidence $evidence): array
    {
        $roots = $directories = $files = $excluded = $errors = [];
        $phpCount = $symbolCount = 0;
        $architectureRoots = [...($options->moduleRoots ?? []), ...($options->projectRoots ?? [])];
        foreach ($options?->roots() ?? [] as $root) {
            $path = ($this->relative)($root->path);
            if (null !== $path) {
                $roots[$path] ??= new CoreDataObject(['id' => 'SR'.(\count($roots) + 1), 'path' => $path, 'namespace' => $root->namespace]);
            } elseif (is_dir($root->path)) {
                $errors[] = ['code' => 'export.path_unavailable', 'path' => null];
            }
        }
        foreach (array_keys($evidence->directories) as $directory) {
            $path = ($this->relative)($directory);
            if (null !== $path) {
                $directories[$path] = true;
            }
        }
        foreach ($evidence->files as $absolute => $file) {
            $path = ($this->relative)($absolute);
            if (null === $path) {
                $errors[] = ['code' => 'export.path_unavailable', 'path' => null];
                continue;
            }
            if (isset($files[$path])) {
                continue;
            }
            $module = $this->owner($absolute, $discovery->modules ?? []);
            $project = $module->projectClass ?? $this->owner($absolute, $discovery->projects ?? [])?->class;
            $extension = pathinfo($path, \PATHINFO_EXTENSION);
            $phpCount += (int) ('php' === strtolower($extension));
            $symbolCount += \count($file['symbols']);
            $inside = array_any($architectureRoots, static fn (DiscoveryRoot $root): bool => $root->contains($absolute));
            $files[$path] = new CoreDataObject([
                'path' => $path, 'extension' => '' === $extension ? null : $extension,
                'size_bytes' => $file['size'], 'content_hash' => null === $file['hash'] ? null : 'sha256:v1:'.$file['hash'],
                'ownership' => null !== $module ? 'module' : (null !== $project ? 'project' : ($inside ? 'unknown' : 'application')),
                'module_class' => $module?->class, 'project_class' => $project,
                'parse_status' => $file['status'],
                'symbols' => array_map(static fn (array $symbol): CoreDataObject => new CoreDataObject($symbol), $file['symbols']),
            ]);
        }
        foreach ($evidence->excluded as $absolute) {
            if (null !== ($path = ($this->relative)($absolute))) {
                $excluded[$path] = true;
            }
        }
        foreach ($evidence->failures as $path => $code) {
            $errors[] = ['code' => $code, 'path' => ($this->relative)($path)];
        }
        ksort($directories, \SORT_STRING);
        ksort($files, \SORT_STRING);
        ksort($excluded, \SORT_STRING);

        return [
            'data' => new CoreDataObject([
                'roots' => array_values($roots),
                'summary' => new CoreDataObject(['directory_count' => \count($directories), 'file_count' => \count($files), 'php_file_count' => $phpCount, 'symbol_count' => $symbolCount]),
                'directories' => array_map('strval', array_keys($directories)), 'files' => array_values($files), 'excluded_paths' => array_map('strval', array_keys($excluded)),
            ]),
            'status' => null === $options ? 'unavailable' : ([] === $errors ? 'complete' : 'partial'),
            'errors' => $errors,
        ];
    }

    /**
     * @template T of \Sillbeam\Core\Module\ModuleDefinition|\Sillbeam\Core\Project\ProjectDefinition
     *
     * @param array<class-string, T> $catalog
     *
     * @return T|null
     */
    private function owner(string $path, array $catalog): ?object
    {
        foreach ($catalog as $definition) {
            if (str_starts_with($path, FilesystemPath::directoryPrefix($definition->directory))) {
                return $definition;
            }
        }

        return null;
    }
}
