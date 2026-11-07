<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture;

use Sillbeam\Core\I18n\CoreMessage;

/**
 * Derives lowercase hyphenated IDs from descriptor class names after removing their suffix.
 *
 * @internal
 */
final class IdDeriver
{
    public static function fromClass(string $class, string $suffix, ?string $file = null): string
    {
        $shortName = substr($class, (int) strrpos($class, '\\') + 1);
        if (str_ends_with($shortName, $suffix)) {
            $shortName = substr($shortName, 0, -\strlen($suffix));
        }

        $id = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $shortName));
        if ('' === $id || !preg_match('/^[a-z][a-z0-9-]*$/', $id)) {
            throw ArchitectureValidationException::fatalMessage('discovery.descriptor_id', new CoreMessage('finding.discovery.descriptor_id', ['class' => $class]), ['class' => $class, 'file' => $file]);
        }

        return $id;
    }
}
