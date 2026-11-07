<?php

declare(strict_types=1);

namespace Sillbeam\Core\Api;

use Composer\InstalledVersions;

/** @api */
final readonly class CoreCapabilitiesProvider implements CoreCapabilitiesProviderInterface
{
    public static function coreVersion(): string
    {
        $package = 'websign-dev/sillbeam-core';
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled($package)) {
            return 'unknown';
        }
        $version = InstalledVersions::getPrettyVersion($package);
        if (null === $version || '' === $version) {
            return 'unknown';
        }
        // Root Composer metadata can describe a different checkout branch.
        if (InstalledVersions::getRootPackage()['name'] === $package && (str_starts_with($version, 'dev-') || str_ends_with($version, '-dev'))) {
            return 'development';
        }

        return $version;
    }

    public function capabilities(): CoreCapabilities
    {
        return new CoreCapabilities(self::coreVersion());
    }
}
