<?php

declare(strict_types=1);

namespace Sillbeam\Core\Api;

/** @api */
interface CoreDataProviderInterface
{
    public function collect(CoreDataRequest $request): CoreDataDocument;
}
