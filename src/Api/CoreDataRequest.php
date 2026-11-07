<?php

declare(strict_types=1);

namespace Sillbeam\Core\Api;

use Sillbeam\Core\I18n\CoreError;
use Sillbeam\Core\I18n\CoreTranslator;

/** @api */
final readonly class CoreDataRequest
{
    public const int SCHEMA_VERSION = 1;
    public ?string $locale;

    public function __construct(public CoreDataProfile $profile, ?string $locale = null, public int $schemaVersion = self::SCHEMA_VERSION)
    {
        if (self::SCHEMA_VERSION !== $schemaVersion) {
            throw CoreError::create(\InvalidArgumentException::class, 'api.unsupported_schema', ['version' => $schemaVersion]);
        }
        if (null !== $locale && !$profile->supportsLocaleSelection()) {
            throw CoreError::create(\InvalidArgumentException::class, 'api.locale_not_selectable', ['profile' => $profile->value]);
        }
        if (null !== $locale && !\in_array($locale, CoreTranslator::locales(), true)) {
            throw CoreError::create(\InvalidArgumentException::class, 'api.unsupported_locale', ['locale' => $locale]);
        }

        $this->locale = $profile->supportsLocaleSelection() ? ($locale ?? CoreTranslator::DEFAULT_LOCALE) : null;
    }

    public static function fromProfile(string $profile, ?string $locale = null, int $schemaVersion = self::SCHEMA_VERSION): self
    {
        $knownProfile = CoreDataProfile::tryFrom($profile)
            ?? throw CoreError::create(\InvalidArgumentException::class, 'api.unsupported_profile', ['profile' => $profile]);

        return new self($knownProfile, $locale, $schemaVersion);
    }
}
