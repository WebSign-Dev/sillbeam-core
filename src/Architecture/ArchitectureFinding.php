<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture;

use Sillbeam\Core\I18n\CoreMessage;
use Sillbeam\Core\I18n\CoreTranslator;

/**
 * One architecture rule violation with scalar source context and error or fatal severity.
 *
 * Error findings permit independent checks to continue; fatal findings stop checks
 * that would depend on unreliable source, ownership or identity information.
 *
 * @api
 */
final readonly class ArchitectureFinding
{
    /**
     * Creates a finding; canonical ordering and deduplication happen in the analysis result.
     *
     * @param array<string, string|int|null> $context
     * @param 'error'|'fatal'                $severity
     * @param string                         $rule     Stable rule identifier, for example dependency.cycle.
     * @param string                         $message  human-readable explanation without terminal formatting
     */
    public function __construct(
        public string $rule,
        public string $message,
        public array $context = [],
        public string $severity = 'error',
        private ?CoreMessage $resource = null,
    ) {
    }

    /** @internal
     * @param array<string, string|int|null> $context
     * @param 'error'|'fatal'                $severity
     */
    public static function fromMessage(string $rule, CoreMessage $resource, array $context = [], string $severity = 'error'): self
    {
        return new self($rule, $resource->render(), $context, $severity, $resource);
    }

    public function localizedMessage(CoreTranslator $translator): string
    {
        return ($this->resource ?? null)?->render($translator) ?? $this->message;
    }

    /** @internal */
    public function withSortedContext(): self
    {
        $context = $this->context;
        ksort($context);

        return new self($this->rule, $this->message, $context, $this->severity, $this->resource ?? null);
    }

    /**
     * Returns a rule-specific remediation hint, or null for rules without a known hint.
     */
    public function suggestion(CoreTranslator $translator = new CoreTranslator()): ?string
    {
        $key = match ($this->rule) {
            'dependency.unknown', 'project.unknown_module' => 'finding.hint.dependency.unknown',
            'module.unknown_override', 'project.unknown_configuration' => 'finding.hint.module.unknown_override',
            'dependency.private', 'code.private', 'project.private_module' => 'finding.hint.dependency.private',
            'dependency.cycle' => 'finding.hint.dependency.cycle',
            'dependency.disabled', 'code.inactive' => 'finding.hint.dependency.disabled',
            'code.unknown_class' => 'finding.hint.code.unknown_class',
            'code.undeclared_dependency' => 'finding.hint.code.undeclared_dependency',
            'module.duplicate_key', 'project.duplicate_id' => 'finding.hint.module.duplicate_key',
            'module.owner_missing', 'discovery.owner_missing' => 'finding.hint.module.owner_missing',
            'project.host_invalid' => 'finding.hint.project.host_invalid',
            'project.host_required' => 'finding.hint.project.host_required',
            'project.host_duplicate' => 'finding.hint.project.host_duplicate',
            'source.parse' => 'finding.hint.source.parse',
            'source.read' => 'finding.hint.source.read',
            'source.duplicate_class' => 'finding.hint.source.duplicate_class',
            'discovery.descriptor_metadata', 'discovery.descriptor_id' => 'finding.hint.discovery.descriptor_metadata',
            'discovery.descriptor_class', 'discovery.descriptor_file' => 'finding.hint.discovery.descriptor_class',
            'discovery.module_overlap', 'discovery.project_overlap', 'discovery.project_inside_module' => 'finding.hint.discovery.module_overlap',
            'discovery.module_boundary', 'discovery.project_boundary' => 'finding.hint.discovery.module_boundary',
            'discovery.multiple_descriptors', 'discovery.multiple_attributes' => 'finding.hint.discovery.multiple_descriptors',
            'report.execution' => 'finding.hint.report.execution',
            default => null,
        };

        return null === $key ? null : $translator->text($key);
    }

    /**
     * Formats the rule, message, available module/Project/file/line context and remediation hint.
     */
    public function describe(): string
    {
        $context = [];
        foreach (['module', 'project', 'file', 'line'] as $key) {
            if (isset($this->context[$key])) {
                $context[] = $key.'='.$this->context[$key];
            }
        }
        $description = '['.$this->rule.'] '.$this->message;
        if ([] !== $context) {
            $description .= ' ('.implode(', ', $context).')';
        }
        if (null !== ($suggestion = $this->suggestion())) {
            $description .= "\nHint: ".$suggestion;
        }

        return $description;
    }
}
