<?php

declare(strict_types=1);

namespace Sillbeam\Core\Api;

use Sillbeam\Core\Architecture\DiscoveryOptions;

/** @api Resolved application configuration for one Core analysis. */
final readonly class CoreDataConfiguration
{
    /** @param array<string, mixed> $config */
    public function __construct(public DiscoveryOptions $options, public string $projectDir, public array $config = [])
    {
    }
}
