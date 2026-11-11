<?php

declare(strict_types=1);

namespace Sillbeam\Core\I18n;

/** Resolves the locale for human-facing output without changing API request defaults. */
final readonly class LocaleResolver
{
    /** @param (\Closure(): ?string)|null $projectLocale Reads project configuration on demand. */
    public function __construct(private UserLocaleStore $userLocale, private ?\Closure $projectLocale = null)
    {
    }

    /** @internal Factory for the project locale resolved during container compilation. */
    public static function forProject(UserLocaleStore $store, string $locale): self
    {
        return new self($store, static fn (): string => $locale);
    }

    public function resolve(?string $explicit = null): string
    {
        $locale = $explicit ?? $this->userLocale->read() ?? $this->projectLocale?->__invoke() ?? CoreTranslator::DEFAULT_LOCALE;

        return self::requireSupported($locale);
    }

    /** @internal */
    public static function requireSupported(string $locale): string
    {
        if (!\in_array($locale, CoreTranslator::locales(), true)) {
            throw CoreError::create(\InvalidArgumentException::class, 'locale.unsupported', ['locale' => $locale, 'supported' => implode(', ', CoreTranslator::locales())]);
        }

        return $locale;
    }
}
