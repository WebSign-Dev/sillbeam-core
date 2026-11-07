<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture;

use Sillbeam\Core\I18n\CoreMessage;
use Sillbeam\Core\Module\ModuleDefinition;
use Sillbeam\Core\Project\ProjectDefinition;

/**
 * Validates descriptor identities, dependency cycles, overrides and host assignments at build time.
 *
 * Candidate graphs from collect() may be invalid; only the strict compile() result
 * or a valid analyzer result may be used to configure runtime services.
 *
 * @internal
 */
final class ArchitectureCompiler
{
    /**
     * Strictly validates and compiles discovered catalogs into activation graphs.
     *
     * @param array<string, mixed> $config
     *
     * @throws ArchitectureValidationException if any architecture finding is collected
     */
    public function compile(DiscoveryResult $discovery, array $config): CompiledArchitecture
    {
        $findings = new ArchitectureFindings();
        $architecture = $this->collect($discovery, $config, $findings);
        (new ArchitectureAnalysisResult($findings->all(), $architecture))->throwIfInvalid();

        return $architecture;
    }

    /**
     * Collects independent validation findings and returns a candidate graph.
     *
     * Mutates the supplied collector; the returned candidate must not be used at runtime
     * until the complete analysis has succeeded.
     *
     * @internal candidate graphs are only for further analysis, never runtime use
     *
     * @param array<string, mixed> $config
     */
    public function collect(DiscoveryResult $discovery, array $config, ArchitectureFindings $findings): CompiledArchitecture
    {
        $modules = $discovery->modules;
        $this->validateIdentities($modules, $discovery->projects, $findings);
        $this->validateDependencies($modules, $findings);
        $this->validateCycles($modules, $findings);
        if ($findings->hasFatal()) {
            // Ambiguous identities cannot safely select overrides or activation roots.
            return new CompiledArchitecture($modules, $discovery->projects, [], []);
        }
        $modules = $this->applyModuleOverrides($modules, $discovery->projects, $config['modules'] ?? [], $findings);
        $projects = $this->configureProjects($discovery->projects, $config['projects'] ?? [], $findings);
        $this->validateHosts($projects, $findings);

        if ([] === $projects) {
            $active = [];
            $visited = [];
            foreach ($modules as $module) {
                if (!$module->isPrivate() && $module->enabled) {
                    $active = [...$active, ...$this->expand($module->class, $modules, $findings, null, $visited)];
                }
            }

            return new CompiledArchitecture($modules, [], array_values(array_unique($active)), []);
        }

        $projectModules = [];
        foreach ($projects as $class => $project) {
            $active = [];
            $visited = [];
            foreach ($project->declaredModules as $moduleClass) {
                $active = [...$active, ...$this->expand($moduleClass, $modules, $findings, $class, $visited, $project->descriptorFile)];
            }
            foreach ($modules as $module) {
                if ($class === $module->projectClass && $module->enabled) {
                    $active = [...$active, ...$this->expand($module->class, $modules, $findings, $class, $visited, $project->descriptorFile)];
                }
            }
            $active = array_values(array_unique($active));
            $projectModules[$class] = $active;
            $projects[$class] = new ProjectDefinition(
                $project->id,
                $project->class,
                $project->description,
                $project->declaredModules,
                $active,
                $project->directory,
                $project->descriptorFile,
                $project->namespace,
                $project->hosts,
                $project->controllerDirectories,
                $project->entityDirectories,
            );
        }

        return new CompiledArchitecture($modules, $projects, [], $projectModules);
    }

    /**
     * @param array<class-string, ModuleDefinition>  $modules
     * @param array<class-string, ProjectDefinition> $projects
     * @param array<string, mixed>                   $overrides
     *
     * @return array<class-string, ModuleDefinition>
     */
    private function applyModuleOverrides(array $modules, array $projects, array $overrides, ArchitectureFindings $findings): array
    {
        $keys = [];
        foreach ($modules as $class => $module) {
            if (null === $module->projectClass) {
                $keys[$module->id] = $class;
                $keys['shared:'.$module->id] = $class;
                continue;
            }
            $project = $projects[$module->projectClass] ?? throw new \LogicException(\sprintf('Private module "%s" has no owning project.', $class));
            $keys['project:'.$project->id.':'.$module->id] = $class;
        }
        foreach (array_keys($overrides) as $key) {
            if (!isset($keys[$key])) {
                $findings->add(ArchitectureFinding::fromMessage('module.unknown_override', new CoreMessage('finding.module.unknown_override', ['key' => $key]), ['override' => $key]));
            }
        }

        foreach ($modules as $class => $module) {
            $projectId = null === $module->projectClass ? null : $projects[$module->projectClass]->id;
            $logicalKey = $module->key($projectId);
            $override = $overrides[$logicalKey] ?? (null === $module->projectClass ? ($overrides[$module->id] ?? []) : []);
            $modules[$class] = new ModuleDefinition(
                $module->id,
                $module->class,
                $module->description,
                $module->dependencies,
                (bool) ($override['enabled'] ?? true),
                $module->directory,
                $module->descriptorFile,
                $module->namespace,
                $module->entityDirectories,
                $module->controllerDirectories,
                $module->projectClass,
            );
        }

        return $modules;
    }

    /**
     * @param array<class-string, ModuleDefinition>  $modules
     * @param array<class-string, ProjectDefinition> $projects
     */
    private function validateIdentities(array $modules, array $projects, ArchitectureFindings $findings): void
    {
        ksort($modules);
        ksort($projects);
        $projectIds = [];
        foreach ($projects as $project) {
            if (isset($projectIds[$project->id])) {
                $findings->add(ArchitectureFinding::fromMessage('project.duplicate_id', new CoreMessage('finding.project.duplicate_id', ['id' => $project->id]), ['project' => $project->class, 'file' => $project->descriptorFile], 'fatal'));
            }
            $projectIds[$project->id] = true;
        }

        $keys = [];
        foreach ($modules as $module) {
            $projectId = null === $module->projectClass ? null : ($projects[$module->projectClass]->id ?? null);
            if (null !== $module->projectClass && null === $projectId) {
                $findings->add(ArchitectureFinding::fromMessage('module.owner_missing', new CoreMessage('finding.module.owner_missing', ['module' => $module->class]), $this->context($module), 'fatal'));
                continue;
            }
            $key = $module->key($projectId);
            if (isset($keys[$key])) {
                $findings->add(ArchitectureFinding::fromMessage('module.duplicate_key', new CoreMessage('finding.module.duplicate_key', ['key' => $key]), $this->context($module), 'fatal'));
            }
            $keys[$key] = true;
        }
    }

    /** @param array<class-string, ModuleDefinition> $modules */
    private function validateDependencies(array $modules, ArchitectureFindings $findings): void
    {
        foreach ($modules as $module) {
            foreach ($module->dependencies as $dependencyClass) {
                $dependency = $modules[$dependencyClass] ?? null;
                $context = [...$this->context($module), 'target' => $dependencyClass];
                if (null === $dependency) {
                    $findings->add(ArchitectureFinding::fromMessage('dependency.unknown', new CoreMessage('finding.dependency.unknown', ['module' => $module->class, 'target' => $dependencyClass]), $context));
                    continue;
                }
                if (!$module->isPrivate() && $dependency->isPrivate()) {
                    $findings->add(ArchitectureFinding::fromMessage('dependency.private', new CoreMessage('finding.dependency.private.shared', ['module' => $module->class, 'target' => $dependency->class]), $context));
                }
                if ($module->isPrivate() && $dependency->isPrivate() && $module->projectClass !== $dependency->projectClass) {
                    $findings->add(ArchitectureFinding::fromMessage('dependency.private', new CoreMessage('finding.dependency.private.cross_project', ['module' => $module->class, 'target' => $dependency->class]), $context));
                }
            }
        }
    }

    /** @param array<class-string, ModuleDefinition> $modules */
    private function validateCycles(array $modules, ArchitectureFindings $findings): void
    {
        $visiting = [];
        $visited = [];
        $path = [];
        $visit = function (string $class) use (&$visit, &$visiting, &$visited, &$path, $modules, $findings): void {
            if (isset($visiting[$class])) {
                $cycle = \array_slice($path, $visiting[$class]);
                sort($cycle);
                $module = $modules[$cycle[0] ?? $class];
                $findings->add(ArchitectureFinding::fromMessage('dependency.cycle', new CoreMessage('finding.dependency.cycle', ['module' => $module->class]), [...$this->context($module), 'cycle' => implode(', ', $cycle)]));

                return;
            }
            if (isset($visited[$class]) || !isset($modules[$class])) {
                return;
            }
            $visiting[$class] = \count($path);
            $path[] = $class;
            $dependencies = $modules[$class]->dependencies;
            sort($dependencies);
            foreach ($dependencies as $dependency) {
                $visit($dependency);
            }
            array_pop($path);
            unset($visiting[$class]);
            $visited[$class] = true;
        };
        $classes = array_keys($modules);
        sort($classes);
        foreach ($classes as $class) {
            $visit($class);
        }
    }

    /**
     * @param class-string                          $class
     * @param array<class-string, ModuleDefinition> $modules
     * @param array<class-string, true>             $visited
     *
     * @return list<class-string>
     */
    private function expand(string $class, array $modules, ArchitectureFindings $findings, ?string $projectClass = null, array &$visited = [], ?string $projectFile = null): array
    {
        $module = $modules[$class] ?? null;
        if (null === $module) {
            // Unknown dependency edges are already reported at their declaring module.
            $findings->add(ArchitectureFinding::fromMessage('project.unknown_module', new CoreMessage('finding.project.unknown_module', ['class' => $class]), ['project' => $projectClass, 'target' => $class, 'file' => $projectFile]));

            return [];
        }
        $context = [...$this->context($module), 'project' => $projectClass];
        $usable = true;
        if (!$module->enabled) {
            $usable = false;
            $findings->add(ArchitectureFinding::fromMessage('dependency.disabled', new CoreMessage('finding.dependency.disabled', ['id' => $module->id]), $context));
        }
        if ($module->isPrivate() && $module->projectClass !== $projectClass) {
            $usable = false;
            $findings->add(ArchitectureFinding::fromMessage('project.private_module', new CoreMessage('finding.project.private_module', ['module' => $module->class]), $context));
        }
        if (!$usable || isset($visited[$class])) {
            return [];
        }
        $visited[$class] = true;
        $expanded = [];
        foreach ($module->dependencies as $dependency) {
            $target = $modules[$dependency] ?? null;
            if (null !== $target && (!$target->isPrivate() || $module->projectClass === $target->projectClass)) {
                $expanded = [...$expanded, ...$this->expand($dependency, $modules, $findings, $projectClass, $visited, $projectFile)];
            }
        }
        $expanded[] = $class;

        return $expanded;
    }

    /**
     * @param array<class-string, ProjectDefinition> $projects
     * @param array<string, mixed>                   $config
     *
     * @return array<class-string, ProjectDefinition>
     */
    private function configureProjects(array $projects, array $config, ArchitectureFindings $findings): array
    {
        $knownIds = array_map(static fn (ProjectDefinition $project): string => $project->id, $projects);
        foreach (array_keys($config) as $id) {
            if (!\in_array($id, $knownIds, true)) {
                $findings->add(ArchitectureFinding::fromMessage('project.unknown_configuration', new CoreMessage('finding.project.unknown_configuration', ['id' => $id]), ['project' => $id]));
            }
        }
        foreach ($projects as $class => $project) {
            $hosts = array_map(static fn (mixed $host): string => strtolower(trim((string) $host)), $config[$project->id]['hosts'] ?? []);
            foreach ($hosts as $host) {
                if ('' === $host || str_contains($host, '://') || str_contains($host, '*') || str_contains($host, '/') || str_contains($host, ':')) {
                    $findings->add(ArchitectureFinding::fromMessage('project.host_invalid', new CoreMessage('finding.project.host_invalid', ['id' => $project->id, 'host' => $host]), ['project' => $project->class, 'file' => $project->descriptorFile, 'host' => $host]));
                }
            }
            $projects[$class] = new ProjectDefinition(
                $project->id,
                $project->class,
                $project->description,
                $project->declaredModules,
                [],
                $project->directory,
                $project->descriptorFile,
                $project->namespace,
                array_values(array_unique($hosts)),
                $project->controllerDirectories,
                $project->entityDirectories,
            );
        }

        return $projects;
    }

    /** @param array<class-string, ProjectDefinition> $projects */
    private function validateHosts(array $projects, ArchitectureFindings $findings): void
    {
        $hosts = [];
        ksort($projects);
        foreach ($projects as $project) {
            if (\count($projects) > 1 && [] === $project->hosts) {
                $findings->add(ArchitectureFinding::fromMessage('project.host_required', new CoreMessage('finding.project.host_required', ['id' => $project->id]), ['project' => $project->class, 'file' => $project->descriptorFile]));
            }
            foreach ($project->hosts as $host) {
                if (isset($hosts[$host])) {
                    $findings->add(ArchitectureFinding::fromMessage('project.host_duplicate', new CoreMessage('finding.project.host_duplicate', ['host' => $host]), ['host' => $host, 'project' => $project->class, 'file' => $project->descriptorFile]));
                }
                $hosts[$host] = $project->id;
            }
        }
    }

    /** @return array{module: class-string, file: string, project: string|null} */
    private function context(ModuleDefinition $module): array
    {
        return ['module' => $module->class, 'file' => $module->descriptorFile, 'project' => $module->projectClass];
    }
}
