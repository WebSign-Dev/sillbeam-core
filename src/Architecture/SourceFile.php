<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture;

/**
 * Parsed source evidence; references retain file-local line numbers and attribute classification.
 *
 * @internal
 */
final readonly class SourceFile
{
    /** @param list<class-string> $classes
     * @param array<class-string, string>                               $descriptors
     * @param list<array{name: string, type: string}>                   $symbols
     * @param list<array{class: string, line: int, kind?: 'attribute'}> $references
     */
    public function __construct(public string $path, public array $classes, public array $descriptors, public array $references = [], public array $symbols = [])
    {
    }
}
