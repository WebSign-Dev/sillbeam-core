<?php

declare(strict_types=1);

namespace Sillbeam\Core\Api;

use Sillbeam\Core\Architecture\FilesystemPath;

/** @internal Verified project-relative paths for one snapshot. */
final class SnapshotPaths
{
    private readonly ?string $root;

    /** @var array<string, string|null> */
    private array $paths = [];

    public function __construct(?string $projectDir)
    {
        $root = null === $projectDir ? false : realpath($projectDir);
        $this->root = false === $root ? null : FilesystemPath::directoryPrefix($root);
    }

    public function relative(string $path): ?string
    {
        if (!\array_key_exists($path, $this->paths)) {
            $absolute = str_contains($path, "\0") ? false : realpath($path);
            $absolute = false === $absolute ? '' : FilesystemPath::normalize($absolute);
            $relative = match (true) {
                null === $this->root || '' === $absolute => '',
                FilesystemPath::directoryPrefix($absolute) === $this->root => '.',
                str_starts_with($absolute, $this->root) => substr($absolute, \strlen($this->root)),
                default => '',
            };
            $this->paths[$path] = '' === $relative || preg_match('~^(?:[A-Za-z]:)|(?:^|/)\.\.(?:/|$)~', $relative) ? null : $relative;
        }

        return $this->paths[$path];
    }
}
