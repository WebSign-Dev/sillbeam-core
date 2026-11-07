<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture\Diagnostic;

use Sillbeam\Core\Architecture\ArchitectureFinding;
use Sillbeam\Core\I18n\CoreTranslator;

/** @internal Communication only: never changes rules, Findings, runtime graphs or reports. */
final readonly class DiagnosticRuleCatalog
{
    public function __construct(private DiagnosticContext $context, private bool $analysisComplete, private CoreTranslator $translator = new CoreTranslator())
    {
    }

    /** @param list<class-string> $cycle Verified ordered witness, never sorted Finding participants. */
    public function explain(ArchitectureFinding $finding, array $cycle = []): FindingExplanation
    {
        [$problem, $impact, $step] = $this->wording($finding);
        $files = $this->files($finding);
        $caution = 'fatal' === $finding->severity || \in_array($finding->rule, ['dependency.cycle', 'dependency.private', 'code.private', 'project.private_module'], true);
        $resolutions = [new Resolution('technical', [$step.$this->translator->text('resolution.rerun')], $files)];
        $module = $this->text($finding, 'module');
        $target = $this->text($finding, 'target');
        $project = $this->text($finding, 'project');
        $class = $this->text($finding, 'class');
        if (null !== $class) {
            $problem .= $this->translator->text('diagnosis.context.class', ['value' => $class]);
        }
        if (null !== $module) {
            $problem .= $this->translator->text('diagnosis.context.module', ['value' => $module]);
        }
        if (null !== $target) {
            $problem .= $this->translator->text('diagnosis.context.target', ['value' => $target]);
        }
        if (null !== $project) {
            $problem .= $this->translator->text('diagnosis.context.project', ['value' => $project]);
        }

        switch ($finding->rule) {
            case 'code.undeclared_dependency':
                [$impact, $resolutions, $caution] = $this->undeclared($finding, $impact, $resolutions);
                break;
            case 'dependency.cycle':
                if ([] !== $cycle) {
                    $problem = $this->translator->text('diagnosis.cycle.witness', ['cycle' => implode(' → ', $cycle)]);
                    $files = array_values($this->context->graph()->sourceDescriptors($cycle));
                }
                $impact = $this->translator->text('diagnosis.cycle.impact');
                $resolutions = [new Resolution('option', [$this->translator->text('resolution.cycle.review', ['files' => [] === $files ? '' : ': '.implode(', ', $files)])], $files)];
                break;
            case 'dependency.private':
            case 'code.private':
            case 'project.private_module':
                [$impact, $resolutions] = $this->privateAccess($finding, $files);
                break;
            case 'dependency.disabled':
            case 'code.inactive':
                [$problem, $impact, $resolutions] = $this->inactive($finding, $problem, $files);
                break;
            case 'dependency.unknown':
            case 'project.unknown_module':
                $descriptor = 'dependency.unknown' === $finding->rule ? ($this->context->modules[$module ?? '']->descriptorFile ?? null) : ($this->context->projects[$project ?? '']->descriptorFile ?? null);
                $resolutions = [new Resolution('technical', [$this->translator->text('resolution.unknown_target', ['target' => $target ?? $this->translator->text('diagnosis.context.reported_target'), 'descriptor' => $descriptor ?? $this->translator->text('diagnosis.context.reported_descriptor')])], $files)];
                break;
            case 'module.unknown_override':
                $key = $this->text($finding, 'override');
                if (null !== $key) {
                    $address = 'sillbeam.modules["'.$key.'"]';
                    $problem .= $this->translator->text('diagnosis.context.configuration', ['value' => $address]);
                    $resolutions = [new Resolution('technical', [$this->translator->text('resolution.module_override', ['address' => $address])], $files, [$address])];
                }
                break;
            case 'project.unknown_configuration':
                if (null !== $project) {
                    $address = 'sillbeam.projects["'.$project.'"]';
                    $resolutions = [new Resolution('technical', [$this->translator->text('resolution.project_configuration', ['address' => $address])], $files, [$address])];
                }
                break;
            case 'project.host_invalid':
            case 'project.host_required':
            case 'project.host_duplicate':
                [$problem, $impact, $resolutions] = $this->hosts($finding, $problem, $impact, $step, $files);
                break;
            case 'module.duplicate_key':
            case 'project.duplicate_id':
                [$problem, $resolutions] = $this->duplicate($finding, $problem, $files);
                break;
        }
        if ('fatal' === $finding->severity) {
            // Parser/descriptor/execution messages may carry evidence absent from scalar context.
            $error = $finding->localizedMessage($this->translator);
            if ($problem !== $error) {
                $problem .= $this->translator->text('diagnosis.context.reported_error', ['error' => $error]);
            }
        }
        if (!$this->analysisComplete) {
            $impact .= $this->translator->text('diagnosis.analysis_incomplete');
            if ('fatal' !== $finding->severity) {
                $resolutions = [new Resolution('technical', [$this->translator->text('resolution.analysis_incomplete')], $files)];
            }
        }

        return new FindingExplanation($finding, $problem, $impact, $resolutions, $caution);
    }

    /** @return array{string, string, string} */
    private function wording(ArchitectureFinding $finding): array
    {
        $key = match ($finding->rule) {
            'dependency.unknown' => 'diagnosis.dependency.unknown',
            'project.unknown_module' => 'diagnosis.project.unknown_module',
            'code.undeclared_dependency' => 'diagnosis.code.undeclared_dependency',
            'dependency.private', 'code.private', 'project.private_module' => 'diagnosis.dependency.private',
            'dependency.cycle' => 'diagnosis.dependency.cycle',
            'dependency.disabled' => 'diagnosis.dependency.disabled',
            'code.inactive' => 'diagnosis.code.inactive',
            'code.unknown_class' => 'diagnosis.code.unknown_class',
            'module.unknown_override' => 'diagnosis.module.unknown_override',
            'project.unknown_configuration' => 'diagnosis.project.unknown_configuration',
            'project.host_invalid' => 'diagnosis.project.host_invalid',
            'project.host_required' => 'diagnosis.project.host_required',
            'project.host_duplicate' => 'diagnosis.project.host_duplicate',
            'module.duplicate_key' => 'diagnosis.module.duplicate_key',
            'project.duplicate_id' => 'diagnosis.project.duplicate_id',
            'module.owner_missing' => 'diagnosis.module.owner_missing',
            'discovery.owner_missing' => 'diagnosis.discovery.owner_missing',
            'discovery.module_boundary' => 'diagnosis.discovery.module_boundary',
            'discovery.project_boundary' => 'diagnosis.discovery.project_boundary',
            'discovery.module_overlap' => 'diagnosis.discovery.module_overlap',
            'discovery.project_overlap' => 'diagnosis.discovery.project_overlap',
            'discovery.project_inside_module' => 'diagnosis.discovery.project_inside_module',
            'discovery.descriptor_class' => 'diagnosis.discovery.descriptor_class',
            'discovery.descriptor_file' => 'diagnosis.discovery.descriptor_file',
            'discovery.descriptor_metadata' => 'diagnosis.discovery.descriptor_metadata',
            'discovery.descriptor_id' => 'diagnosis.discovery.descriptor_id',
            'discovery.multiple_descriptors' => 'diagnosis.discovery.multiple_descriptors',
            'discovery.multiple_attributes' => 'diagnosis.discovery.multiple_attributes',
            'source.parse' => 'diagnosis.source.parse',
            'source.read' => 'diagnosis.source.read',
            'source.duplicate_class' => 'diagnosis.source.duplicate_class',
            'report.execution' => 'diagnosis.report.execution',
            default => 'diagnosis.unknown',
        };

        $problem = 'diagnosis.unknown' === $key ? $finding->localizedMessage($this->translator) : $this->translator->text($key.'.problem');

        return [$problem, $this->translator->text($key.'.impact'), $this->translator->text($key.'.step')];
    }

    /** @param list<Resolution> $fallback
     * @return array{string, list<Resolution>, bool}
     */
    private function undeclared(ArchitectureFinding $finding, string $impact, array $fallback): array
    {
        $source = $this->text($finding, 'module');
        $target = $this->text($finding, 'target');
        if (!$this->analysisComplete || !$this->context->complete || null === $source || null === $target || null === $this->context->relationship($source, $target)) {
            return [$impact.$this->translator->text('diagnosis.dependency.insufficient'), $fallback, true];
        }
        $assessment = $this->context->assessDependency($source, $target);
        $activation = $this->activationNotice($assessment);
        if (false === $assessment->privacyAllowed) {
            [$impact, $resolutions] = $this->privateAccess($finding, $this->files($finding));

            return [$impact.('' === $activation ? '' : ' '.$activation), $resolutions, true];
        }
        if (null !== $assessment->returnPath) {
            $chain = [$source, ...$assessment->returnPath];
            $files = array_values(array_unique([...$this->files($finding), ...array_values($this->context->graph()->sourceDescriptors($assessment->returnPath))]));

            return [$this->translator->text('diagnosis.dependency.would_cycle', ['chain' => implode(' → ', $chain), 'existing' => implode(' → ', $assessment->returnPath)]).('' === $activation ? '' : ' '.$activation), [new Resolution('option', [$this->translator->text('resolution.dependency.avoid_cycle', ['files' => implode(', ', $files)])], $files)], true];
        }
        $resolution = $assessment->resolution($this->translator);
        if (null !== $resolution) {
            return [$impact.('' === $activation ? $this->translator->text('diagnosis.dependency.no_conflict') : ' '.$activation), [$resolution], '' !== $activation];
        }

        $reason = true === $assessment->alreadyDeclared
            ? $this->translator->text('diagnosis.dependency.already_declared')
            : (\in_array('unresolved_dependencies', $assessment->blockers, true)
                ? $this->translator->text('diagnosis.dependency.unresolved')
                : $this->translator->text('diagnosis.dependency.unknown_state'));

        return [$impact.' '.$reason.('' === $activation ? '' : ' '.$activation), $fallback, true];
    }

    private function activationNotice(DependencyAssessment $assessment): string
    {
        $notes = [];
        if ('active' !== $assessment->sourceState) {
            $notes[] = match ($assessment->sourceState) {
                'disabled' => $this->translator->text('diagnosis.activation.source_disabled'),
                'unassigned' => $this->translator->text('diagnosis.activation.source_unassigned'),
                default => $this->translator->text('diagnosis.activation.source_unknown'),
            };
        }
        if ('active' !== $assessment->targetState) {
            $notes[] = match ($assessment->targetState) {
                'disabled' => $this->translator->text('diagnosis.activation.target_disabled'),
                'unassigned' => $this->translator->text('diagnosis.activation.target_unassigned'),
                default => $this->translator->text('diagnosis.activation.target_unknown'),
            };
        }
        $unassigned = array_keys(array_filter($assessment->targetProjectStates, static fn (string $state): bool => 'unassigned' === $state));
        if ('active' === $assessment->targetState && [] !== $unassigned) {
            $notes[] = $this->translator->text('diagnosis.activation.project_unassigned', ['projects' => implode(', ', $unassigned)]);
        }
        if (\in_array('unknown', $assessment->targetProjectStates, true)) {
            $notes[] = $this->translator->text('diagnosis.activation.project_unknown');
        }

        return implode(' ', $notes);
    }

    /** @param list<string> $files
     * @return array{string, list<Resolution>}
     */
    private function privateAccess(ArchitectureFinding $finding, array $files): array
    {
        $target = $this->text($finding, \in_array($finding->rule, ['project.private_module', 'dependency.disabled'], true) ? 'module' : 'target');
        $owner = null === $target ? null : ($this->context->modules[$target]->projectClass ?? null);
        $impact = (null === $owner ? $this->translator->text('diagnosis.privacy.owner_unknown') : $this->translator->text('diagnosis.privacy.owner', ['target' => $target, 'owner' => $owner])).$this->translator->text('diagnosis.privacy.boundary');

        return [$impact, [new Resolution('option', [$this->translator->text('resolution.privacy.keep')], $files)]];
    }

    /** @param list<string> $files
     * @return array{string, string, list<Resolution>}
     */
    private function inactive(ArchitectureFinding $finding, string $problem, array $files): array
    {
        $target = $this->text($finding, 'dependency.disabled' === $finding->rule ? 'module' : 'target');
        $state = null === $target || !$this->analysisComplete ? 'unknown' : $this->context->state($target);
        $definition = null === $target ? null : ($this->context->modules[$target] ?? null);
        $requestingProject = $this->text($finding, 'project');
        $source = $this->text($finding, 'module');
        $forbidden = 'dependency.disabled' === $finding->rule
            ? null !== $definition?->projectClass && $definition->projectClass !== $requestingProject
            : (null !== $source && null !== $target && false === $this->context->assessDependency($source, $target)->privacyAllowed);
        if ($this->analysisComplete && $this->context->complete && $forbidden && null !== $target) {
            [$impact, $options] = $this->privateAccess($finding, $files);
            $key = $this->context->moduleConfigurationKey($target);
            $address = null === $key ? null : 'sillbeam.modules["'.$key.'"].enabled';
            $impact .= $this->translator->text('diagnosis.privacy.target_disabled').(null === $address ? '' : $this->translator->text('diagnosis.privacy.owner_configuration', ['address' => $address]));
            $resolutions = array_map(static fn (Resolution $option): Resolution => new Resolution($option->kind, $option->steps, $option->files, null === $address ? [] : [$address]), $options);

            return [$problem, $impact, $resolutions];
        }
        if ('disabled' === $state && null !== $target) {
            $key = $this->context->moduleConfigurationKey($target);
            $address = null === $key ? null : 'sillbeam.modules["'.$key.'"].enabled';
            $step = null === $address ? $this->translator->text('diagnosis.activation.verify_override') : $this->translator->text('resolution.activation.enable', ['address' => $address]);

            return [$problem, $this->translator->text('diagnosis.activation.config_disabled', ['target' => $target]), [new Resolution('option', [$step.$this->translator->text('resolution.activation.rerun')], $files, null === $address ? [] : [$address])]];
        }
        if ('unassigned' === $state && null !== $target) {
            $source = $this->text($finding, 'module');
            $projects = null === $source ? [] : array_keys($this->context->assessDependency($source, $target)->targetProjectStates);
            $projectFiles = [];
            foreach ($projects as $project) {
                $projectFiles[] = $this->context->projects[$project]->descriptorFile;
            }
            $step = [] === $projectFiles ? $this->translator->text('resolution.activation.choose_project', ['target' => $target]) : $this->translator->text('resolution.activation.select_module', ['target' => '\\'.ltrim($target, '\\').'::class', 'files' => implode($this->translator->text('diagnosis.list.or'), $projectFiles)]);

            $impact = $this->translator->text('diagnosis.activation.config_unassigned', ['target' => $target]);
            if (null !== $source && false === $this->context->assessDependency($source, $target)->alreadyDeclared) {
                $impact .= $this->translator->text('diagnosis.activation.no_direct_dependency');
            }

            return [$problem, $impact, [new Resolution('option', [$step.$this->translator->text('resolution.activation.restructure')], array_values(array_unique([...$files, ...$projectFiles])))]];
        }

        return [$problem, $this->translator->text('diagnosis.activation.unknown'), [new Resolution('technical', [$this->translator->text('resolution.activation.verify')], $files)]];
    }

    /** @param list<string> $files
     * @return array{string, string, list<Resolution>}
     */
    private function hosts(ArchitectureFinding $finding, string $problem, string $impact, string $step, array $files): array
    {
        $project = $this->text($finding, 'project');
        $id = null === $project ? null : $this->context->projectConfigurationId($project);
        $address = null === $id ? null : 'sillbeam.projects["'.$id.'"].hosts';
        $addresses = null === $address ? [] : [$address];
        $host = $this->text($finding, 'host');
        if (null !== $host) {
            $problem .= $this->translator->text('diagnosis.host.reported', ['host' => $host]);
        }
        if ('project.host_duplicate' === $finding->rule && null !== $host) {
            $owners = $this->context->projectsForHost($host);
            foreach ($owners as $owner) {
                $files[] = $owner->descriptorFile;
                $ownerId = $this->context->projectConfigurationId($owner->class);
                if (null !== $ownerId) {
                    $addresses[] = 'sillbeam.projects["'.$ownerId.'"].hosts';
                }
            }
            if ([] !== $owners) {
                $impact .= $this->translator->text('diagnosis.host.projects', ['projects' => implode(', ', array_column($owners, 'class'))]);
            }
        }
        $addresses = array_values(array_unique($addresses));
        sort($addresses, \SORT_STRING);
        $files = array_values(array_unique($files));
        sort($files, \SORT_STRING);
        $step .= ([] === $addresses ? '' : $this->translator->text('resolution.host.review', ['addresses' => implode(', ', array_unique($addresses))])).$this->translator->text('resolution.rerun');

        return [$problem, $impact, [new Resolution('option', [$step], array_values(array_unique($files)), array_values(array_unique($addresses)))]];
    }

    /** @param list<string> $files
     * @return array{string, list<Resolution>}
     */
    private function duplicate(ArchitectureFinding $finding, string $problem, array $files): array
    {
        if ('module.duplicate_key' === $finding->rule) {
            $module = $this->context->modules[$this->text($finding, 'module') ?? ''] ?? null;
            if (null !== $module) {
                foreach ($this->context->modules as $other) {
                    if ($other->id === $module->id && $other->projectClass === $module->projectClass) {
                        $files[] = $other->descriptorFile;
                    }
                }
                $problem .= $this->translator->text('diagnosis.duplicate.module_id', ['id' => $module->id, 'scope' => $module->projectClass ?? 'shared']);
            }
            $step = $this->translator->text('resolution.duplicate.module');
        } else {
            $project = $this->context->projects[$this->text($finding, 'project') ?? ''] ?? null;
            if (null !== $project) {
                foreach ($this->context->projects as $other) {
                    if ($other->id === $project->id) {
                        $files[] = $other->descriptorFile;
                    }
                }
                $problem .= $this->translator->text('diagnosis.duplicate.project_id', ['id' => $project->id]);
            }
            $step = $this->translator->text('resolution.duplicate.project');
        }

        $files = array_values(array_unique($files));
        sort($files, \SORT_STRING);

        return [$problem, [new Resolution('option', [$step.$this->translator->text('resolution.duplicate.review', ['files' => [] === $files ? $this->translator->text('diagnosis.context.reported_descriptors') : implode(', ', array_unique($files))])], array_values(array_unique($files)))]];
    }

    private function text(ArchitectureFinding $finding, string $key): ?string
    {
        $value = $finding->context[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /** @return list<string> */
    private function files(ArchitectureFinding $finding): array
    {
        $files = [];
        $file = $this->text($finding, 'file');
        if (null !== $file) {
            $files[] = $file;
        }
        foreach (['module', 'target', 'project', 'class'] as $key) {
            $class = $this->text($finding, $key);
            if (null !== $class) {
                $descriptor = $this->context->modules[$class]->descriptorFile ?? $this->context->projects[$class]->descriptorFile ?? null;
                if (null !== $descriptor) {
                    $files[] = $descriptor;
                }
            }
        }

        return array_values(array_unique($files));
    }
}
