<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture\Console;

use Sillbeam\Core\Architecture\ArchitectureAnalysisResult;
use Sillbeam\Core\Architecture\ArchitectureFinding;
use Sillbeam\Core\Architecture\ArchitectureReport;
use Sillbeam\Core\Architecture\Diagnostic\DiagnosticCollection;
use Sillbeam\Core\Architecture\Diagnostic\DiagnosticContext;
use Sillbeam\Core\I18n\CoreTranslator;

/** @internal Scalar human view of actual diagnostic evidence, separate from schema-1 JSON. */
final class ArchitecturePresentation
{
    /** @return array<string, mixed> */
    public static function fromAnalysis(ArchitectureAnalysisResult $analysis, ?string $projectDir = null, CoreTranslator $translator = new CoreTranslator()): array
    {
        $context = DiagnosticContext::fromAnalysis($analysis);
        $collection = DiagnosticCollection::fromContext($analysis->findings, $context, $translator);
        $root = $projectDir ?? $analysis->discovery?->root;
        if (null !== $root && 'src' === basename($root)) {
            $root = \dirname($root);
        }
        $labels = [];
        $paths = [];
        foreach ($context->projects as $project) {
            $labels[$project->class] = self::shortClass($project->class, 'Project');
            $paths[$project->descriptorFile] = self::relative($project->descriptorFile, $root, $context);
        }
        foreach ($context->modules as $module) {
            $name = self::shortClass($module->class, 'Module');
            $labels[$module->class] = null === $module->projectClass ? $name : ($labels[$module->projectClass] ?? self::shortClass($module->projectClass, 'Project')).' / '.$name;
            $paths[$module->descriptorFile] = self::relative($module->descriptorFile, $root, $context);
        }
        foreach ($analysis->findings as $finding) {
            if (\is_string($finding->context['file'] ?? null)) {
                $paths[$finding->context['file']] = self::relative($finding->context['file'], $root, $context);
            }
        }
        $readable = static fn (string $text): string => strtr(self::friendly($text, $labels), $paths);
        $issues = $messages = $hints = [];
        foreach ($collection->diagnoses as $diagnosis) {
            $first = $diagnosis->findings[0];
            $rule = $first->rule;
            [$title, $category] = self::heading($rule, \count($diagnosis->findings), $translator);
            $source = $first->context['module'] ?? $first->context['project'] ?? null;
            $target = $first->context['target'] ?? $first->context['class'] ?? null;
            $relation = [] !== $diagnosis->modules && 'declared_cycle' === $diagnosis->basis
                ? implode(' → ', array_map(static fn (string $class): string => $labels[$class] ?? $class, $diagnosis->modules))
                : (\is_string($source) ? ($labels[$source] ?? self::shortClass($source, 'Module')) : $translator->text('diagnosis.heading.application')).(\is_string($target) ? ' → '.($labels[$target] ?? $target) : '');
            $summary = [];
            $impacts = [];
            $references = [];
            $originals = [];
            $rules = [];
            $caution = false;
            foreach ($diagnosis->details as $detail) {
                $finding = $detail->finding;
                $summary[] = $readable($detail->problem);
                $impacts[] = $readable($detail->impact);
                $rules[] = $finding->rule;
                $caution = $caution || $detail->caution;
                $file = $finding->context['file'] ?? null;
                $line = $finding->context['line'] ?? null;
                if (\is_string($file)) {
                    $location = self::relative($file, $root, $context).(\is_int($line) ? ':'.$line : '');
                    $reference = ['file' => $file, 'line' => \is_int($line) ? $line : null, 'readable' => $location];
                    $references[serialize([$file, $line])] = $reference;
                }
                $original = get_object_vars($finding);
                $message = $finding->localizedMessage($translator);
                $key = ArchitectureReport::findingKey($original);
                $messages[$key] = $message;
                if (null !== ($hint = $finding->suggestion($translator))) {
                    $hints[$key] = $hint;
                }
                $originals[] = [
                    'finding' => $original,
                    'message' => $message,
                    'readable' => $readable($message).(\is_string($file) ? $translator->text('diagnosis.context.at', ['location' => self::relative($file, $root, $context).(\is_int($line) ? ':'.$line : '')]) : ''),
                    'technical' => '['.$finding->rule.'] '.$translator->text('cli.label.'.$finding->severity).': '.$message.' '.$translator->text('architecture.cli.technical_context').' '.json_encode($finding->context, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE),
                ];
            }
            foreach ($diagnosis->descriptors as $file) {
                if (!array_any($references, static fn (array $reference): bool => $file === $reference['file'])) {
                    $references[serialize([$file, null])] = ['file' => $file, 'line' => null, 'readable' => self::relative($file, $root, $context)];
                }
            }
            $steps = [];
            foreach ($diagnosis->resolutions as $resolution) {
                foreach ($resolution->steps as $step) {
                    $steps[] = ('option' === $resolution->kind ? $translator->text('diagnosis.heading.option') : '').$readable($step);
                }
            }
            $impact = implode(' ', array_unique($impacts));
            $issues[] = [
                'title' => $title, 'category' => $category, 'relation' => $relation,
                'problem' => implode(' ', array_unique($summary)), 'impact' => $impact,
                // Markdown may need different identity labels without reversing already-shortened CLI text.
                'original_problem' => implode(' ', array_unique(array_map(static fn ($detail): string => $detail->problem, $diagnosis->details))),
                'original_impact' => implode(' ', array_unique(array_map(static fn ($detail): string => $detail->impact, $diagnosis->details))),
                'relation_modules' => 'declared_cycle' === $diagnosis->basis ? $diagnosis->modules : [],
                'summary' => implode(' ', array_unique($summary)).($caution ? '' : ' '.$impact),
                'caution' => $caution ? $impact : null,
                'steps' => array_values(array_unique($steps)), 'findings' => \count($diagnosis->findings),
                'references' => array_values($references), 'rule' => implode(', ', array_unique($rules)),
                'detail' => $translator->text('diagnosis.context.evidence', ['basis' => $diagnosis->basis]).implode(' ', array_unique(array_merge(...array_map(static fn ($resolution): array => $resolution->configuration, $diagnosis->resolutions)))),
                'originals' => $originals,
            ];
        }
        $couplings = [];
        foreach ($analysis->applicationReferences as $source => $references) {
            $targets = [];
            foreach ($references as $reference) {
                $targets[$reference['class']][] = $reference;
            }
            ksort($targets);
            foreach ($targets as $class => $evidence) {
                $name = $labels[$source] ?? self::shortClass($source, 'Module');
                $couplings[] = ['source' => $source, 'relation' => $name.' → '.$class, 'summary' => $translator->text('diagnosis.application_reference', ['module' => $name]), 'references' => $evidence];
            }
        }

        return [
            'modules' => \count($context->modules), 'projects' => \count($context->projects),
            'findings' => $collection->findingCount(), 'issues' => $issues, 'couplings' => $couplings, 'finding_messages' => $messages, 'finding_hints' => $hints,
            'status' => !$analysis->isComplete() ? 'unavailable' : ($analysis->isValid() ? 'valid' : 'violations'),
        ];
    }

    /** Legacy/custom report providers may supply schema data without an analysis snapshot.
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function fromData(array $data, CoreTranslator $translator = new CoreTranslator()): array
    {
        $findings = [];
        foreach ($data['findings'] as $finding) {
            $context = $finding['context'];
            $findings[] = new ArchitectureFinding($finding['rule'], $finding['message'], $context, $finding['severity']);
        }
        $view = self::fromAnalysis(new ArchitectureAnalysisResult($findings), translator: $translator);
        $view['modules'] = \count($data['modules']);
        $view['projects'] = \count($data['projects']);
        $view['status'] = $data['status'];
        foreach ($data['modules'] as $module) {
            $targets = [];
            foreach ($module['application_references'] ?? [] as $reference) {
                $targets[$reference['class']][] = $reference;
            }
            foreach ($targets as $class => $references) {
                $view['couplings'][] = ['source' => $module['class'], 'relation' => $module['id'].' → '.$class, 'summary' => $translator->text('diagnosis.application_reference', ['module' => $module['id']]), 'references' => $references];
            }
        }

        return $view;
    }

    private static function shortClass(string $class, string $suffix): string
    {
        $name = basename(str_replace('\\', '/', $class));

        return str_ends_with($name, $suffix) && \strlen($name) > \strlen($suffix) ? substr($name, 0, -\strlen($suffix)) : $name;
    }

    private static function relative(string $file, ?string $root, DiagnosticContext $context): string
    {
        foreach ($context->modules as $module) {
            if (!$module->isPrivate() && str_starts_with($file, $module->directory.'/')) {
                return basename($module->directory).'/'.substr($file, \strlen($module->directory) + 1);
            }
        }
        if (null !== $root && str_starts_with($file, rtrim($root, '/').'/')) {
            return substr($file, \strlen(rtrim($root, '/')) + 1);
        }
        // No project root is known for custom reports: retain a readable suffix, never invent a root.
        if (false !== ($index = strpos($file, '/src/'))) {
            return substr($file, $index + 1);
        }

        return ltrim($file, '/');
    }

    /** @param array<string, string> $labels */
    private static function friendly(string $text, array $labels): string
    {
        if ([] === $labels) {
            return $text;
        }
        uksort($labels, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
        $pattern = '~(?<![a-zA-Z0-9_\\\\])(?:'.implode('|', array_map(static fn (string $key): string => preg_quote($key, '~'), array_keys($labels))).')(?![a-zA-Z0-9_\\\\]|::class)~';

        return preg_replace_callback($pattern, static fn (array $match): string => $labels[$match[0]], $text) ?? $text;
    }

    /** @return array{string, string} */
    public static function heading(string $rule, int $count, CoreTranslator $translator = new CoreTranslator()): array
    {
        return match ($rule) {
            'dependency.cycle' => [$translator->text('diagnosis.heading.cycle'), $translator->text('diagnosis.heading.graph')],
            'code.undeclared_dependency' => $count > 1 ? [$translator->text('diagnosis.heading.undeclared_plural'), $translator->text('diagnosis.heading.missing_declarations')] : [$translator->text('diagnosis.heading.undeclared'), $translator->text('diagnosis.heading.declaration')],
            'code.unknown_class' => [$count > 1 ? $translator->text('diagnosis.heading.classes_unresolved') : $translator->text('diagnosis.heading.class_unresolved'), $translator->text('diagnosis.heading.unresolved')],
            'dependency.disabled' => [$translator->text('diagnosis.heading.disabled'), $translator->text('diagnosis.heading.activation')],
            'code.inactive' => [$translator->text('diagnosis.heading.inactive'), $translator->text('diagnosis.heading.activation')],
            'dependency.private', 'code.private', 'project.private_module' => [$translator->text('diagnosis.heading.private'), $translator->text('diagnosis.heading.boundary')],
            'dependency.unknown' => [$translator->text('diagnosis.heading.unknown_dependency'), $translator->text('diagnosis.heading.declaration')],
            'project.unknown_module' => [$translator->text('diagnosis.heading.unknown_module'), $translator->text('diagnosis.heading.activation')],
            'module.duplicate_key', 'project.duplicate_id' => [$translator->text('diagnosis.heading.duplicate'), $translator->text('diagnosis.heading.identity')],
            'project.host_required' => [$translator->text('diagnosis.heading.host_required'), $translator->text('diagnosis.heading.hosts')],
            'project.host_invalid' => [$translator->text('diagnosis.heading.host_invalid'), $translator->text('diagnosis.heading.hosts')],
            'project.host_duplicate' => [$translator->text('diagnosis.heading.host_duplicate'), $translator->text('diagnosis.heading.hosts')],
            'module.unknown_override', 'project.unknown_configuration' => [$translator->text('diagnosis.heading.unknown_configuration'), $translator->text('diagnosis.heading.configuration')],
            'source.parse' => [$translator->text('diagnosis.heading.parse'), $translator->text('diagnosis.heading.incomplete')],
            'source.read' => [$translator->text('diagnosis.heading.read'), $translator->text('diagnosis.heading.incomplete')],
            'source.duplicate_class' => [$translator->text('diagnosis.heading.duplicate_class'), $translator->text('diagnosis.heading.incomplete')],
            'report.execution' => [$translator->text('diagnosis.heading.execution'), $translator->text('diagnosis.heading.incomplete')],
            'discovery.owner_missing' => [$translator->text('diagnosis.heading.discovery.owner_missing'), $translator->text('diagnosis.heading.architecture')],
            'discovery.module_boundary' => [$translator->text('diagnosis.heading.discovery.module_boundary'), $translator->text('diagnosis.heading.architecture')],
            'discovery.project_boundary' => [$translator->text('diagnosis.heading.discovery.project_boundary'), $translator->text('diagnosis.heading.architecture')],
            'discovery.module_overlap' => [$translator->text('diagnosis.heading.discovery.module_overlap'), $translator->text('diagnosis.heading.architecture')],
            'discovery.project_overlap' => [$translator->text('diagnosis.heading.discovery.project_overlap'), $translator->text('diagnosis.heading.architecture')],
            'discovery.project_inside_module' => [$translator->text('diagnosis.heading.discovery.project_inside_module'), $translator->text('diagnosis.heading.architecture')],
            'discovery.descriptor_class' => [$translator->text('diagnosis.heading.discovery.descriptor_class'), $translator->text('diagnosis.heading.architecture')],
            'discovery.descriptor_file' => [$translator->text('diagnosis.heading.discovery.descriptor_file'), $translator->text('diagnosis.heading.architecture')],
            'discovery.descriptor_metadata' => [$translator->text('diagnosis.heading.discovery.descriptor_metadata'), $translator->text('diagnosis.heading.architecture')],
            'discovery.descriptor_id' => [$translator->text('diagnosis.heading.discovery.descriptor_id'), $translator->text('diagnosis.heading.architecture')],
            'discovery.multiple_descriptors' => [$translator->text('diagnosis.heading.discovery.multiple_descriptors'), $translator->text('diagnosis.heading.architecture')],
            'discovery.multiple_attributes' => [$translator->text('diagnosis.heading.discovery.multiple_attributes'), $translator->text('diagnosis.heading.architecture')],
            default => [ucfirst(str_replace(['.', '_'], ' ', $rule)), $translator->text('diagnosis.heading.architecture')],
        };
    }
}
