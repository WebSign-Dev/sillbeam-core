<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture;

/** @internal */
final class SourceIndexSnapshot
{
    /** @var array<string, mixed> */
    private array $previous = [];

    /** @var array<string, array{string, list<class-string>, array<class-string, string>, list<array{class: string, line: int, kind?: 'attribute'}>, list<array{name: string, type: string}>}> */
    private array $current = [];

    private int $hits = 0;

    /** @param int<1, 33554432> $maxBytes */
    public function __construct(private readonly string $file, private readonly int $maxBytes)
    {
        if (is_link($file) || !is_file($file)) {
            return;
        }

        $memoryLimit = ini_parse_quantity((string) \ini_get('memory_limit'));
        // The decoded PHP arrays and temporary JSON strings cost much more than the file.
        $readableBytes = $memoryLimit > 0 ? min($maxBytes, intdiv(max(0, $memoryLimit - memory_get_usage(true)), 32)) : $maxBytes;
        $size = @filesize($file);
        if ($readableBytes < 67 || false === $size || $size > $readableBytes) {
            return;
        }
        try {
            $bytes = @file_get_contents($file, length: $readableBytes + 1);
            if (false === $bytes || \strlen($bytes) > $readableBytes || "\n" !== ($bytes[64] ?? null)) {
                return;
            }
            $payload = substr($bytes, 65);
            if (substr($bytes, 0, 64) !== hash('sha256', basename($this->file).$payload)) {
                return;
            }
            $entries = json_decode($payload, true, flags: \JSON_THROW_ON_ERROR);
            if (\is_array($entries)) {
                $this->previous = $entries;
            }
        } catch (\JsonException) {
            // A malformed snapshot is equivalent to a cold scan.
        }
    }

    public function parse(SourceFileParser $parser, string $path, string $contents, bool $inventoryOnly, bool $collectSymbols): SourceFile
    {
        $digest = hash('sha256', $contents);
        $entry = $this->previous[$path] ?? null;
        if (\is_array($entry) && $digest === ($entry[0] ?? null) && self::valid($entry)) {
            ++$this->hits;
            $parsed = new SourceFile($path, $entry[1], $entry[2], $entry[3], $entry[4]);
        } else {
            $parsed = $parser->parseContents($path, $contents, $inventoryOnly, $collectSymbols);
        }
        $this->current[$path] = [$digest, $parsed->classes, $parsed->descriptors, $parsed->references, $parsed->symbols];

        return $parsed;
    }

    public function publish(): void
    {
        if ($this->hits === \count($this->current) && \count($this->previous) === \count($this->current)) {
            return;
        }
        try {
            $payload = json_encode($this->current, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // PHP paths and identifiers may contain bytes that JSON cannot represent.
            return;
        }
        $bytes = hash('sha256', basename($this->file).$payload)."\n".$payload;
        if (\strlen($bytes) > $this->maxBytes) {
            return;
        }
        // tempnam can fall back to the system directory. Publish only from the same directory.
        $directory = \dirname($this->file);
        $temp = @tempnam($directory, '.index-');
        if (false === $temp) {
            return;
        }
        try {
            if (\dirname($temp) === $directory && @file_put_contents($temp, $bytes) === \strlen($bytes)) {
                @rename($temp, $this->file);
            }
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /** @param array<mixed> $entry */
    private static function valid(array $entry): bool
    {
        return 5 === \count($entry)
            && \is_array($entry[1] ?? null) && array_is_list($entry[1]) && array_all($entry[1], static fn (mixed $class): bool => \is_string($class))
            && \is_array($entry[2] ?? null) && array_all($entry[2], static fn (mixed $descriptor): bool => \is_string($descriptor))
            && \is_array($entry[3] ?? null) && array_is_list($entry[3])
            && array_all($entry[3], static fn (mixed $ref): bool => \is_array($ref) && \is_string($ref['class'] ?? null) && \is_int($ref['line'] ?? null) && (!\array_key_exists('kind', $ref) || 'attribute' === $ref['kind']))
            && \is_array($entry[4] ?? null) && array_is_list($entry[4])
            && array_all($entry[4], static fn (mixed $symbol): bool => \is_array($symbol) && \is_string($symbol['name'] ?? null) && \in_array($symbol['type'] ?? null, ['class', 'interface', 'trait', 'enum'], true));
    }
}
