<?php

declare(strict_types=1);

namespace Sillbeam\Core\Api;

/** @api */
final readonly class CoreCapabilitiesProvider implements CoreCapabilitiesProviderInterface
{
    public function capabilities(): CoreCapabilities
    {
        return new CoreCapabilities('0.4.0');
    }
}
