<?php

declare(strict_types=1);

namespace Sillbeam\Core\Api;

/** @api Contract metadata is independent of application analysis. */
interface CoreCapabilitiesProviderInterface
{
    public function capabilities(): CoreCapabilities;
}
