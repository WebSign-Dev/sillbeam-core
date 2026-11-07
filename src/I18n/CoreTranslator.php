<?php

declare(strict_types=1);

namespace Sillbeam\Core\I18n;

/** @api Use one instance per output locale and export. */
final class CoreTranslator
{
    public const string DEFAULT_LOCALE = 'en';

    /** @var array<string, string> */
    private const array CATALOGS = [
        'en' => __DIR__.'/Resources/en.php',
        'de' => __DIR__.'/Resources/de.php',
    ];

    /** @var array<string, array<string, string>> */
    private static array $loaded = [];

    /** @var array<string, array{resource_key: string, locale: string, source_locale: string}> */
    private array $fallbacks = [];

    public function __construct(public readonly string $locale = self::DEFAULT_LOCALE)
    {
    }

    /** @return list<string> */
    public static function locales(): array
    {
        return array_keys(self::CATALOGS);
    }

    /** @param array<string, string|int> $parameters */
    public function text(string $key, array $parameters = []): string
    {
        $template = self::catalog($this->locale)[$key] ?? null;
        if (null === $template) {
            $template = self::catalog(self::DEFAULT_LOCALE)[$key] ?? throw new \LogicException('Unknown Core resource key: '.$key);
            $this->fallbacks[$key] = ['resource_key' => $key, 'locale' => $this->locale, 'source_locale' => self::DEFAULT_LOCALE];
        }
        $replacements = [];
        foreach ($parameters as $name => $value) {
            $replacements['{'.$name.'}'] = (string) $value;
        }

        return strtr($template, $replacements);
    }

    /** @return list<array{resource_key: string, locale: string, source_locale: string}> */
    public function fallbacks(): array
    {
        return array_values($this->fallbacks);
    }

    /** @return array<string, string> */
    private static function catalog(string $locale): array
    {
        if (!isset(self::CATALOGS[$locale])) {
            return [];
        }

        return self::$loaded[$locale] ??= require self::CATALOGS[$locale];
    }
}
