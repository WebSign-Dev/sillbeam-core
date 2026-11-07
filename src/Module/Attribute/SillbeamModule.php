<?php

declare(strict_types=1);

namespace Sillbeam\Core\Module\Attribute;

use Sillbeam\Core\I18n\CoreError;

/**
 * Declares a module descriptor and its direct module dependencies.
 *
 * The attribute carries metadata only; discovery and compilation determine ownership and activation.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class SillbeamModule
{
    /**
     * Creates descriptor metadata; does not load or activate referenced classes.
     *
     * @param list<class-string> $dependencies descriptor classes of direct dependencies; IDs are not accepted
     * @param string|null        $id           explicit lowercase ID matching [a-z][a-z0-9-]*, or null to derive it from the descriptor class
     * @param string             $description  human-readable catalog description
     *
     * @throws \InvalidArgumentException if an explicit ID has invalid syntax
     */
    public function __construct(
        public ?string $id = null,
        public string $description = '',
        public array $dependencies = [],
    ) {
        if (null !== $id && !preg_match('/^[a-z][a-z0-9-]*$/D', $id)) {
            throw CoreError::create(\InvalidArgumentException::class, 'error.discovery.module_id', ['id' => $id]);
        }
    }
}
