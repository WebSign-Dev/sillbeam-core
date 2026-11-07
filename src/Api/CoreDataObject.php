<?php

declare(strict_types=1);

namespace Sillbeam\Core\Api;

use Sillbeam\Core\I18n\CoreError;

/**
 * Immutable JSON object for nested v1 structures, including empty objects and maps.
 *
 * @phpstan-type JsonValue null|bool|int|float|string|CoreDataObject|list<mixed>
 *
 * @api
 */
final readonly class CoreDataObject implements \JsonSerializable
{
    /** @var array<array-key, JsonValue> */
    public array $fields;

    /** @param array<array-key, mixed> $fields */
    public function __construct(array $fields = [])
    {
        $values = [];
        foreach ($fields as $key => $value) {
            $values[$key] = self::copyValue($value);
        }
        $this->fields = $values;
    }

    /** @return JsonValue */
    private static function copyValue(mixed $value): mixed
    {
        if (\is_array($value) && array_is_list($value)) {
            return array_map(self::copyValue(...), $value);
        }
        if (null === $value || \is_scalar($value) || $value instanceof self) {
            return $value;
        }

        throw CoreError::create(\InvalidArgumentException::class, 'api.invalid_json_value');
    }

    public function jsonSerialize(): object
    {
        return (object) $this->fields;
    }
}
