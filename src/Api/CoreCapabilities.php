<?php

declare(strict_types=1);

namespace Sillbeam\Core\Api;

use Sillbeam\Core\I18n\CoreTranslator;

/** @api */
final readonly class CoreCapabilities implements \JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(public string $coreVersion)
    {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $profiles = [];
        foreach (CoreDataProvider::supportedProfiles() as $profile) {
            $profiles[$profile->value] = [
                'schema_versions' => [CoreDataRequest::SCHEMA_VERSION],
                'default_schema_version' => CoreDataRequest::SCHEMA_VERSION,
                'supports_locale_selection' => $profile->supportsLocaleSelection(),
                'domains' => $profile->domains(),
            ];
        }

        return [
            'document_type' => 'sillbeam_core_capabilities',
            'schema_version' => self::SCHEMA_VERSION,
            'core_version' => $this->coreVersion,
            'default_locale' => CoreTranslator::DEFAULT_LOCALE,
            'available_locales' => CoreTranslator::locales(),
            'profiles' => $profiles,
            'operations' => ['architecture', 'doctor', 'graph', 'inspect_module'],
        ];
    }
}
