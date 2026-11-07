<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture\Diagnostic;

use Sillbeam\Core\I18n\CoreTranslator;

/** @internal Local constraints on one hypothetical declared edge, not whole-architecture approval. */
final readonly class DependencyAssessment
{
    /** @param list<class-string>|null $returnPath
     * @param array<class-string, 'active'|'disabled'|'unassigned'|'unknown'> $targetProjectStates
     * @param list<string>                                                    $blockers
     * @param 'active'|'disabled'|'unassigned'|'unknown'                      $sourceState
     * @param 'active'|'disabled'|'unassigned'|'unknown'                      $targetState
     */
    public function __construct(
        public string $source,
        public string $target,
        public ?string $sourceDescriptor,
        public ?array $returnPath,
        public ?bool $hasReturnPath,
        public ?bool $privacyAllowed,
        public ?bool $alreadyDeclared,
        public string $sourceState,
        public string $targetState,
        public array $targetProjectStates,
        public array $blockers,
    ) {
    }

    public function resolution(CoreTranslator $translator = new CoreTranslator()): ?Resolution
    {
        if ([] !== $this->blockers || false !== $this->hasReturnPath || true !== $this->privacyAllowed || false !== $this->alreadyDeclared || null === $this->sourceDescriptor) {
            return null;
        }

        // Adding a dependency is always a domain decision, even if local checks pass.
        return new Resolution('option', [$translator->text('resolution.dependency.declare', ['descriptor' => $this->sourceDescriptor, 'target' => '\\'.ltrim($this->target, '\\').'::class'])], [$this->sourceDescriptor]);
    }
}
