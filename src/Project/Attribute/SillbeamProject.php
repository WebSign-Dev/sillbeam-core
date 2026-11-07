<?php

declare(strict_types=1);

namespace Sillbeam\Core\Project\Attribute;

use Sillbeam\Core\I18n\CoreError;

/**
 * Declares a Project descriptor and the shared modules selected by that Project.
 *
 * Enabled private modules and transitive dependencies are added during compilation.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class SillbeamProject
{
    /**
     * Creates descriptor metadata; does not load or activate referenced classes.
     *
     * @param list<class-string> $modules     shared-module descriptor classes selected by this Project; dependencies expand during compilation
     * @param string|null        $id          explicit lowercase ID matching [a-z][a-z0-9-]*, or null to derive it from the descriptor class
     * @param string             $description human-readable catalog description
     *
     * @throws \InvalidArgumentException if an explicit ID has invalid syntax
     */
    public function __construct(
        public ?string $id = null,
        public string $description = '',
        public array $modules = [],
    ) {
        if (null !== $id && !preg_match('/^[a-z][a-z0-9-]*$/D', $id)) {
            throw CoreError::create(\InvalidArgumentException::class, 'error.discovery.project_id', ['id' => $id]);
        }
    }
}
