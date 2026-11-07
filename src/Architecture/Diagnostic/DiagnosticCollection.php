<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture\Diagnostic;

use Sillbeam\Core\Architecture\ArchitectureAnalysisResult;
use Sillbeam\Core\Architecture\ArchitectureFinding;
use Sillbeam\Core\I18n\CoreTranslator;

/** @internal Complete attribution with deterministic run-local IDs, not persistent issue numbers. */
final readonly class DiagnosticCollection
{
    /** @param list<ArchitectureFinding> $findings
     * @param list<Diagnosis>    $diagnoses
     * @param array<int, string> $assignments finding position => diagnosis ID, exactly once
     */
    private function __construct(public array $findings, public array $diagnoses, public array $assignments)
    {
    }

    public static function fromAnalysis(ArchitectureAnalysisResult $analysis, CoreTranslator $translator = new CoreTranslator()): self
    {
        return self::fromContext($analysis->findings, DiagnosticContext::fromAnalysis($analysis), $translator);
    }

    /** @param list<ArchitectureFinding> $findings */
    public static function fromContext(array $findings, DiagnosticContext $context, CoreTranslator $translator = new CoreTranslator()): self
    {
        $cycles = $context->graph()->cycles();
        $groups = [];
        foreach ($findings as $index => $finding) {
            [$key, $basis, $modules] = self::relationship($finding, $index, $context, $cycles);
            $groups[$key] ??= ['basis' => $basis, 'modules' => $modules, 'indexes' => [], 'findings' => [], 'fatal' => false];
            $groups[$key]['indexes'][] = $index;
            $groups[$key]['findings'][] = $finding;
            $groups[$key]['fatal'] = $groups[$key]['fatal'] || 'fatal' === $finding->severity;
        }
        ksort($groups, \SORT_STRING);
        // Fatal findings are actual prerequisites for reliable analysis, not a rule-code rank.
        uasort($groups, static fn (array $left, array $right): int => $right['fatal'] <=> $left['fatal']);
        $catalog = new DiagnosticRuleCatalog($context, !array_any($findings, static fn (ArchitectureFinding $finding): bool => 'fatal' === $finding->severity), $translator);
        $diagnoses = [];
        $assignments = [];
        foreach ($groups as $group) {
            $id = 'D'.(\count($diagnoses) + 1);
            $descriptors = [];
            foreach ($group['modules'] as $class) {
                if (isset($context->modules[$class])) {
                    $descriptors[$class] = $context->modules[$class]->descriptorFile;
                }
            }
            foreach ($group['findings'] as $finding) {
                foreach (['module', 'target', 'project', 'class'] as $field) {
                    $class = $finding->context[$field] ?? null;
                    if (\is_string($class) && isset($context->modules[$class])) {
                        $descriptors[$context->modules[$class]->class] = $context->modules[$class]->descriptorFile;
                    } elseif (\is_string($class) && isset($context->projects[$class])) {
                        $descriptors[$context->projects[$class]->class] = $context->projects[$class]->descriptorFile;
                    }
                }
            }
            $details = [];
            $resolutions = [];
            $narrative = [];
            foreach ($group['findings'] as $finding) {
                $detail = $catalog->explain($finding, 'declared_cycle' === $group['basis'] ? $group['modules'] : []);
                $details[] = $detail;
                $location = $detail->location();
                $paragraph = $detail->problem.(null === $location ? '' : $translator->text('diagnosis.context.location', ['location' => $location])).' '.$detail->impact;
                $narrative[$paragraph] = $paragraph;
                foreach ($detail->resolutions as $resolution) {
                    $configuration = $resolution->configuration;
                    sort($configuration, \SORT_STRING);
                    $key = serialize([$resolution->kind, $resolution->steps, $configuration]);
                    $files = array_values(array_unique([...($resolutions[$key]->files ?? []), ...$resolution->files]));
                    sort($files, \SORT_STRING);
                    $resolutions[$key] = new Resolution($resolution->kind, $resolution->steps, $files, $configuration);
                }
            }
            foreach ($group['indexes'] as $index) {
                $assignments[$index] = $id;
            }
            $diagnoses[] = new Diagnosis($id, $group['basis'], implode("\n", $narrative), $group['indexes'], $group['findings'], $group['modules'], $descriptors, array_values($resolutions), $details);
        }
        ksort($assignments, \SORT_NUMERIC);

        return new self($findings, $diagnoses, $assignments);
    }

    public function findingCount(): int
    {
        return \count($this->findings);
    }

    public function diagnosisCount(): int
    {
        return \count($this->diagnoses);
    }

    /** @param list<list<class-string>> $cycles
     * @return array{string, 'finding'|'declared_cycle'|'declared_dependency'|'observed_reference'|'disabled_module'|'duplicate_module_key'|'duplicate_project_id'|'host_assignment', list<class-string>}
     */
    private static function relationship(ArchitectureFinding $finding, int $index, DiagnosticContext $context, array $cycles): array
    {
        $moduleClass = $finding->context['module'] ?? null;
        $projectClass = $finding->context['project'] ?? null;
        if (\in_array($finding->rule, ['dependency.disabled', 'code.inactive'], true)) {
            $target = 'dependency.disabled' === $finding->rule ? $moduleClass : ($finding->context['target'] ?? null);
            if (\is_string($target) && 'disabled' === $context->state($target) && isset($context->modules[$target])) {
                return ['disabled:'.$target, 'disabled_module', [$context->modules[$target]->class]];
            }
        }
        if ('module.duplicate_key' === $finding->rule && \is_string($moduleClass) && isset($context->modules[$moduleClass])) {
            $module = $context->modules[$moduleClass];
            $peers = array_filter($context->modules, static fn ($other): bool => $other->id === $module->id && $other->projectClass === $module->projectClass);
            if (\count($peers) > 1) {
                return ['module-key:'.serialize([$module->projectClass, $module->id]), 'duplicate_module_key', array_values(array_column($peers, 'class'))];
            }
        }
        if ('project.duplicate_id' === $finding->rule && \is_string($projectClass) && isset($context->projects[$projectClass])) {
            $project = $context->projects[$projectClass];
            $peers = array_filter($context->projects, static fn ($other): bool => $other->id === $project->id);
            if (\count($peers) > 1) {
                return ['project-id:'.$project->id, 'duplicate_project_id', []];
            }
        }
        $host = $finding->context['host'] ?? null;
        if ('project.host_duplicate' === $finding->rule && \is_string($host) && \count($context->projectsForHost($host)) > 1) {
            return ['host:'.$host, 'host_assignment', []];
        }
        // Legacy Finding metadata lists sorted participants; verify a real ordered witness instead.
        if ('dependency.cycle' === $finding->rule && \is_string($finding->context['cycle'] ?? null)) {
            $participants = explode(', ', $finding->context['cycle']);
            sort($participants, \SORT_STRING);
            foreach ($cycles as $cycle) {
                $members = array_values(array_unique($cycle));
                sort($members, \SORT_STRING);
                if ($participants === $members && \in_array($finding->context['module'] ?? null, $cycle, true)) {
                    return ['cycle:'.serialize($cycle), 'declared_cycle', $cycle];
                }
            }
        }
        $source = $finding->context['module'] ?? null;
        $target = $finding->context['target'] ?? null;
        if (\is_string($source) && \is_string($target) && null !== ($relationship = $context->relationship($source, $target))) {
            return ['relationship:'.serialize([$source, $target]), $relationship['basis'], [$relationship['source'], $relationship['target']]];
        }

        return ['finding:'.$index, 'finding', []];
    }
}
