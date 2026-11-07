<?php

declare(strict_types=1);

namespace Sillbeam\Core\Api;

/** @api Serialize the existing document according to its request's schema version. */
interface CoreDataSerializerInterface
{
    /** @throws \JsonException */
    public function serialize(CoreDataDocument $document): string;
}
