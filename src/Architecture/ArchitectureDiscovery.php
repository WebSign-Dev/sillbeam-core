<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture;

use Sillbeam\Core\Module\ModuleDiscovery;
use Sillbeam\Core\Project\ProjectDefinition;
use Sillbeam\Core\Project\ProjectDiscovery;

/**
 * Discovers Projects before modules so private ownership and nested integration directories are unambiguous.
 *
 * @internal
 */
final readonly class ArchitectureDiscovery
{
    public function __construct(private SourceInspector $inspector = new SourceInspector())
    {
    }

    /**
     * Builds a source catalog and tracked cache resources; descriptor loading may execute application code.
     *
     * @throws ArchitectureValidationException if source, descriptor identity or ownership is ambiguous
     */
    public function discover(DiscoveryOptions $options): DiscoveryResult
    {
        $projects = (new ProjectDiscovery($this->inspector))->discover($options);
        $modules = (new ModuleDiscovery($this->inspector))->discover($options, $projects);
        $moduleDirectories = array_values(array_map(static fn ($module): string => $module->directory, $modules));
        foreach ($projects as $class => $project) {
            [$controllers, $entities] = $this->inspector->directoryGroups(
                $project->directory, [$options->controllerPatterns, $options->entityPatterns], $moduleDirectories, $options,
            );
            $projects[$class] = new ProjectDefinition(
                $project->id, $project->class, $project->description, $project->declaredModules, [],
                $project->directory, $project->descriptorFile, $project->namespace, [],
                $controllers, $entities,
            );
        }
        $resources = array_map(static fn (DiscoveryRoot $root): string => $root->path, $options->roots());
        $resources = [...$resources, ...array_keys($this->inspector->files($options))];

        return new DiscoveryResult($options->root, $options->namespace, $modules, $projects, array_values(array_unique($resources)));
    }
}
