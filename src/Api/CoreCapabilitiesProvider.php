<?php

declare(strict_types=1);

namespace Sillbeam\Core\Api;

/** @api */
final readonly class CoreCapabilitiesProvider implements CoreCapabilitiesProviderInterface
{
    public const string CORE_VERSION = '0.4.0';

    public function capabilities(): CoreCapabilities
    {
        return new CoreCapabilities(self::CORE_VERSION);
    }
}
