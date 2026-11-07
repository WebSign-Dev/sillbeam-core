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

    private ?string $capturePrefix = null;
    /** @var array<string, array{string, array<string, string|int>}> */
    private array $captured = [];
    /** @var array<string, string> */
    private array $tokens = [];

    /** @internal Capture messages during evidence collection for later rendering. */
    public static function capture(): self
    {
        $translator = new self();
        $translator->capturePrefix = '~sb'.bin2hex(random_bytes(12)).'_';

        return $translator;
    }

    /** @internal */
    public function isCaptured(string $text): bool
    {
        return isset($this->captured[$text]);
    }

    /** @internal Render captured resources, including nested resource parameters. */
    public function resolve(string $text, self $target): string
    {
        if (null === $this->capturePrefix) {
            return $text;
        }

        return preg_replace_callback('/'.$this->capturePrefix.'[0-9]+~/', function (array $match) use ($target): string {
            [$key, $parameters] = $this->captured[$match[0]];

            return $target->text($key, array_map(fn (string|int $value): string|int => \is_string($value) ? $this->resolve($value, $target) : $value, $parameters));
        }, $text) ?? $text;
    }

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
        if (null !== $this->capturePrefix) {
            $identity = serialize([$key, $parameters]);
            if (!isset($this->tokens[$identity])) {
                $token = $this->capturePrefix.\count($this->captured).'~';
                $this->captured[$token] = [$key, $parameters];
                $this->tokens[$identity] = $token;
            }

            return $this->tokens[$identity];
        }
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
