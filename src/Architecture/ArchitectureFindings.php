<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture;

/** @internal Mutable only within a single analysis run. */
final class ArchitectureFindings
{
    /** @var array<string, ArchitectureFinding> */
    private array $findings = [];

    public function add(ArchitectureFinding $finding): void
    {
        $finding = $finding->withSortedContext();
        $this->findings[serialize([$finding->rule, $finding->message, $finding->context, $finding->severity])] = $finding;
    }

    public function hasFatal(): bool
    {
        foreach ($this->findings as $finding) {
            if ('fatal' === $finding->severity) {
                return true;
            }
        }

        return false;
    }

    /** @return list<ArchitectureFinding> */
    public function all(): array
    {
        $findings = array_values($this->findings);
        $key = static fn (ArchitectureFinding $finding): array => [
            $finding->rule, $finding->context['file'] ?? '', $finding->context['line'] ?? 0,
            serialize($finding->context), $finding->severity, $finding->message,
        ];
        usort($findings, static fn (ArchitectureFinding $a, ArchitectureFinding $b): int => $key($a) <=> $key($b));

        return $findings;
    }
}
