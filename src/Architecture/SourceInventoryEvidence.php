<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture;

/** @internal File observations shared by discovery and the optional inventory. */
final class SourceInventoryEvidence
{
    /** @var array<string, string|null> */
    public array $directories = [];
    /** @var array<string, array{size: int|null, hash: string|null, status: string, symbols: list<array{name: string, type: string}>}> */
    public array $files = [];
    /** @var list<string> */
    public array $excluded = [];
    /** @var array<string, string> */
    public array $failures = [];

    public function directory(string $path): void
    {
        if (\array_key_exists($path, $this->directories)) {
            return;
        }
        $this->directories[$path] = self::directoryDigest($path);
        if (null === $this->directories[$path]) {
            $this->failures[$path] = 'inventory.read';
        }
    }

    public function file(string $path): void
    {
        if (isset($this->files[$path])) {
            return;
        }
        $this->files[$path] = ['size' => null, 'hash' => null, 'status' => 'not_parsed', 'symbols' => []];
        if ('php' !== strtolower(pathinfo($path, \PATHINFO_EXTENSION))) {
            $size = @filesize($path);
            $hash = @hash_file('sha256', $path);
            $this->files[$path]['size'] = false === $size ? null : $size;
            $this->files[$path]['hash'] = false === $hash ? null : $hash;
            if (false === $size || false === $hash) {
                $this->failures[$path] = 'inventory.read';
            }
        }
    }

    public function php(string $path, ?string $contents, ?SourceFile $parsed): void
    {
        $this->files[$path] = [
            'size' => null === $contents ? null : \strlen($contents),
            'hash' => null === $contents ? null : hash('sha256', $contents),
            'status' => null === $parsed ? 'failed' : 'parsed',
            'symbols' => $parsed->symbols ?? [],
        ];
        if (null === $parsed) {
            $this->failures[$path] = null === $contents ? 'inventory.read' : 'inventory.parse';
        }
    }

    /** @return list<string> */
    public function changed(): array
    {
        clearstatcache(true);
        $changes = [];
        foreach ($this->directories as $path => $digest) {
            if (null !== $digest && self::directoryDigest($path) !== $digest) {
                $changes[] = $path;
            }
        }
        foreach ($this->files as $path => $file) {
            if (null !== $file['hash'] && @hash_file('sha256', $path) !== $file['hash']) {
                $changes[] = $path;
            }
        }

        return $changes;
    }

    private static function directoryDigest(string $path): ?string
    {
        $entries = @scandir($path);

        return false === $entries ? null : hash('sha256', serialize($entries));
    }
}
