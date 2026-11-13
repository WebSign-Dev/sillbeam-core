<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture;

/**
 * Resolves strict directory prefixes by walking the ancestors of a source path.
 *
 * @internal
 *
 * @template T
 */
final readonly class AreaDirectoryMap
{
    /** @param array<string, T> $directories */
    public function __construct(private array $directories)
    {
    }

    /** @return \Generator<int, T> Most specific directory first. */
    public function containing(string $path): \Generator
    {
        while (false !== ($separator = strrpos($path, '/'))) {
            $path = substr($path, 0, $separator);
            if (isset($this->directories[$path])) {
                yield $this->directories[$path];
            }
        }
    }

    /** @return T|null */
    public function owner(string $path): mixed
    {
        return $this->containing($path)->current();
    }
}
