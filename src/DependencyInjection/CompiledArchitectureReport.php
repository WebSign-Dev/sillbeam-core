<?php

declare(strict_types=1);

namespace Sillbeam\Core\DependencyInjection;

use Sillbeam\Core\Architecture\ArchitectureReport;

/**
 * Restores the scalar report embedded in the Symfony container at build time.
 *
 * @internal
 */
final class CompiledArchitectureReport
{
    public static function restore(string $payload): ArchitectureReport
    {
        /** @var array{array<string, mixed>, array<string, mixed>} $report */
        $report = unserialize(base64_decode($payload), ['allowed_classes' => false]);

        return new ArchitectureReport(...$report);
    }
}
