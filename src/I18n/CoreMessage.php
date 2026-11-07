<?php

declare(strict_types=1);

namespace Sillbeam\Core\I18n;

/** @internal A text resource and its original, unlocalized parameters. */
final readonly class CoreMessage
{
    /** @param array<string, string|int|self> $parameters */
    public function __construct(public string $key, public array $parameters = [])
    {
    }

    public function render(?CoreTranslator $translator = null): string
    {
        $translator ??= new CoreTranslator();
        $parameters = array_map(static fn (string|int|self $value): string|int => $value instanceof self ? $value->render($translator) : $value, $this->parameters);

        return $translator->text($this->key, $parameters);
    }
}
