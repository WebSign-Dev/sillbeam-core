<?php

declare(strict_types=1);

namespace Sillbeam\Core\Routing;

use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Config\Resource\ResourceInterface;
use Symfony\Component\Config\Resource\SelfCheckingResourceChecker;
use Symfony\Component\Config\Resource\SelfCheckingResourceInterface;

/**
 * Tracks already canonicalized PHP paths with the same existence and mtime checks as FileResource.
 *
 * @internal
 */
final readonly class SourceFilesResource implements SelfCheckingResourceInterface
{
    private string $id;

    /** @param list<string> $paths */
    public function __construct(private array $paths)
    {
        $this->id = self::class.':'.hash('xxh128', implode("\0", $paths));
    }

    /**
     * Keeps native check order when a different resource interrupts a run of files.
     *
     * @param iterable<ResourceInterface>         $resources
     * @param (callable(FileResource): bool)|null $allows
     *
     * @return \Generator<int, ResourceInterface>
     */
    public static function compact(iterable $resources, ?callable $allows = null): \Generator
    {
        $files = [];
        foreach ($resources as $resource) {
            if ($resource instanceof FileResource && (null === $allows || $allows($resource))) {
                $files[$resource->getResource()] = $resource;
                continue;
            }
            if ([] !== $files) {
                yield 1 === \count($files) ? reset($files) : new self(array_keys($files));
                $files = [];
            }
            yield $resource;
        }
        if ([] !== $files) {
            yield 1 === \count($files) ? reset($files) : new self(array_keys($files));
        }
    }

    public function __toString(): string
    {
        return $this->id;
    }

    public function isFresh(int $timestamp): bool
    {
        $checker = new SelfCheckingResourceChecker();
        // Reuse the native per-path cache keys, including checks from other Symfony caches.
        $file = new class implements SelfCheckingResourceInterface {
            public string $path;

            public function __toString(): string
            {
                return $this->path;
            }

            public function isFresh(int $timestamp): bool
            {
                $mtime = @filemtime($this->path);

                return false !== $mtime && $mtime <= $timestamp;
            }
        };
        foreach ($this->paths as $path) {
            $file->path = $path;
            if (!$checker->isFresh($file, $timestamp)) {
                return false;
            }
        }

        return true;
    }
}
