<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture\Console;

use Sillbeam\Core\I18n\CoreError;

/** @internal Publishes complete rendered SVG using same-directory atomic filesystem operations. */
final class SvgOutput
{
    /**
     * Resolves the real parent and rejects unsafe targets before expensive rendering.
     * Missing parent directories are created recursively. Symbolic links are rejected at validation, even with --force.
     *
     * @throws \RuntimeException for unavailable parents or protected targets
     */
    public function destination(string $path, bool $force): string
    {
        if ('' === trim($path) || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            throw CoreError::create(\RuntimeException::class, 'cli.svg.path');
        }
        $directory = \dirname($path);
        if (!@is_dir($directory) && !@mkdir($directory, 0777, true) && !@is_dir($directory)) {
            throw CoreError::create(\RuntimeException::class, 'cli.svg.directory', ['path' => $directory]);
        }
        $parent = @realpath($directory);
        if (false === $parent || !@is_dir($parent) || !@is_writable($parent)) {
            throw CoreError::create(\RuntimeException::class, 'cli.svg.writable', ['path' => \dirname($path)]);
        }
        $destination = $parent.\DIRECTORY_SEPARATOR.basename($path);
        $this->check($destination, $force);

        return $destination;
    }

    /**
     * Stages only successful renderer output. No-clobber publication uses an atomic
     * hard link; explicit replacement uses rename. Fails safely if unsupported.
     * Forced rename can replace a concurrently changed directory entry; it never
     * follows a symbolic link to overwrite the referenced file.
     *
     * @throws \RuntimeException when staging or publication fails
     */
    public function write(string $destination, string $svg, bool $force): void
    {
        $temporary = @tempnam(\dirname($destination), '.sillbeam-svg-');
        if (false === $temporary) {
            throw CoreError::create(\RuntimeException::class, 'cli.svg.temporary');
        }
        try {
            if (\strlen($svg) !== @file_put_contents($temporary, $svg)) {
                throw CoreError::create(\RuntimeException::class, 'cli.svg.write');
            }
            $this->check($destination, $force);
            $published = $force ? @rename($temporary, $destination) : @link($temporary, $destination);
            if (!$published) {
                throw CoreError::create(\RuntimeException::class, 'cli.svg.publish');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function check(string $destination, bool $force): void
    {
        clearstatcache(true, $destination);
        if (@is_link($destination) || (@file_exists($destination) && !@is_file($destination))) {
            throw CoreError::create(\RuntimeException::class, 'cli.svg.regular', ['path' => $destination]);
        }
        if (!$force && @file_exists($destination)) {
            throw CoreError::create(\RuntimeException::class, 'cli.svg.exists', ['path' => $destination]);
        }
    }
}
