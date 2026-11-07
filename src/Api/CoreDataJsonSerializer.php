<?php

declare(strict_types=1);

namespace Sillbeam\Core\Api;

/** @api */
final readonly class CoreDataJsonSerializer implements CoreDataSerializerInterface
{
    public function serialize(CoreDataDocument $document): string
    {
        return json_encode($document, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
