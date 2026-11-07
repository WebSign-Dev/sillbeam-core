<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture;

use Sillbeam\Core\I18n\CoreMessage;

/**
 * Strict validation failure carrying all available findings and the original fatal cause.
 *
 * Extends LogicException for compatibility with existing build-time error handling.
 *
 * @api
 */
final class ArchitectureValidationException extends \LogicException
{
    /**
     * Builds a multiline message from all findings and preserves the previous exception.
     *
     * @param ArchitectureAnalysisResult $result   rejected result with all available evidence
     * @param \Throwable|null            $previous original parser, descriptor or execution failure
     */
    public function __construct(public readonly ArchitectureAnalysisResult $result, ?\Throwable $previous = null)
    {
        parent::__construct(implode("\n", array_map(
            static fn (ArchitectureFinding $finding): string => $finding->describe(),
            $result->findings,
        )), 0, $previous);
    }

    /**
     * Creates an incomplete result with one fatal finding and the original cause.
     *
     * @param array<string, string|int|null> $context
     *
     * @internal used by discovery and source parsing to signal expected fatal failures
     */
    public static function fatal(string $rule, string $message, array $context = [], ?\Throwable $previous = null): self
    {
        return new self(new ArchitectureAnalysisResult([new ArchitectureFinding($rule, $message, $context, 'fatal')], cause: $previous), $previous);
    }

    /** @internal
     * @param array<string, string|int|null> $context
     */
    public static function fatalMessage(string $rule, CoreMessage $message, array $context = [], ?\Throwable $previous = null): self
    {
        return new self(new ArchitectureAnalysisResult([ArchitectureFinding::fromMessage($rule, $message, $context, 'fatal')], cause: $previous), $previous);
    }
}
