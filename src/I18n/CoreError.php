<?php

declare(strict_types=1);

namespace Sillbeam\Core\I18n;

/**
 * Associates text resources with existing exception types. Legacy consumers
 * retain the same class, message, cause and exception lifetime.
 *
 * @internal
 */
final class CoreError
{
    /** @var \WeakMap<\Throwable, CoreMessage>|null */
    private static ?\WeakMap $resources = null;

    /**
     * @template T of \LogicException
     *
     * @param class-string<T>           $type
     * @param array<string, string|int> $parameters
     *
     * @return T
     */
    public static function create(string $type, string $key, array $parameters = []): \LogicException
    {
        $resource = new CoreMessage($key, $parameters);
        $error = new $type($resource->render());
        self::$resources ??= new \WeakMap();
        self::$resources[$error] = $resource;

        return $error;
    }

    public static function message(\Throwable $error): CoreMessage|string
    {
        return self::$resources[$error] ?? $error->getMessage();
    }

    public static function render(\Throwable $error, CoreTranslator $translator): string
    {
        $message = self::message($error);

        return $message instanceof CoreMessage ? $message->render($translator) : $message;
    }
}
