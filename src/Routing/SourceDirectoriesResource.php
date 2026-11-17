<?php

declare(strict_types=1);

namespace Sillbeam\Core\Routing;

use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\Config\Resource\ResourceInterface;
use Symfony\Component\Config\Resource\SelfCheckingResourceChecker;
use Symfony\Component\Config\Resource\SelfCheckingResourceInterface;

/**
 * Checks a source root and its direct child roots, all with the same filter.
 *
 * @internal
 */
final readonly class SourceDirectoriesResource implements SelfCheckingResourceInterface
{
    private string $id;

    /** @param list<DirectoryResource> $children */
    public function __construct(private DirectoryResource $root, private array $children)
    {
        $this->id = self::class.':'.hash('xxh128', implode("\0", [$root, ...$children]));
    }

    /**
     * Groups only adjacent, directly nested roots on Unix filesystems.
     *
     * @param list<ResourceInterface>                  $resources
     * @param (callable(DirectoryResource): bool)|null $allows
     *
     * @return \Generator<int, ResourceInterface>
     */
    public static function compact(array $resources, ?callable $allows = null): \Generator
    {
        $count = \count($resources);
        for ($i = 0; $i < $count; ++$i) {
            $root = $resources[$i];
            if ('/' !== \DIRECTORY_SEPARATOR || !$root instanceof DirectoryResource
                || !str_starts_with($root->getResource(), '/') || (null !== $allows && !$allows($root))
            ) {
                yield $root;
                continue;
            }
            $children = [];
            while (($child = $resources[$i + 1] ?? null) instanceof DirectoryResource
                && \dirname($child->getResource()) === $root->getResource()
                && $child->getPattern() === $root->getPattern()
                && (null === $allows || $allows($child))
            ) {
                $children[] = $child;
                ++$i;
            }
            yield [] === $children ? $root : new self($root, $children);
        }
    }

    public function __toString(): string
    {
        return $this->id;
    }

    public function isFresh(int $timestamp): bool
    {
        $checker = new SelfCheckingResourceChecker();
        $directory = new class implements SelfCheckingResourceInterface {
            public DirectoryResource $resource;
            public bool $covered = false;
            public bool $scanned = false;

            public function __toString(): string
            {
                return (string) $this->resource;
            }

            public function isFresh(int $timestamp): bool
            {
                if ($this->covered) {
                    return true;
                }
                $this->scanned = true;

                return $this->resource->isFresh($timestamp);
            }
        };

        $directory->resource = $this->root;
        if (!$checker->isFresh($directory, $timestamp)) {
            return false;
        }
        $childCheck = clone $directory;
        foreach ($this->children as $child) {
            $path = $child->getResource();
            $childCheck->resource = $child;
            // A cached parent result cannot establish coverage. Native iteration also skips links.
            $childCheck->covered = $directory->scanned && !is_link($path) && is_dir($path)
                && false !== ($mtime = @filemtime($path)) && $mtime <= $timestamp;
            if (!$checker->isFresh($childCheck, $timestamp)) {
                return false;
            }
        }

        return true;
    }
}
