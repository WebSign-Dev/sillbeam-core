<?php

declare(strict_types=1);

namespace Sillbeam\Core\Api;

/** @api */
enum CoreDataProfile: string
{
    case Live = 'live';
    case Analysis = 'analysis';

    public function supportsLocaleSelection(): bool
    {
        return self::Live === $this;
    }

    /** @return list<string> */
    public function domains(): array
    {
        $common = ['architecture', 'diagnostics', 'references'];

        return self::Live === $this ? $common : [...$common, 'graph', 'source_inventory', 'doctor'];
    }
}
