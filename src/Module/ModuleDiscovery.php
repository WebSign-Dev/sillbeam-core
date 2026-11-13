<?php

declare(strict_types=1);

namespace Sillbeam\Core\Module;

use Sillbeam\Core\Architecture\ArchitectureValidationException;
use Sillbeam\Core\Architecture\DiscoveryOptions;
use Sillbeam\Core\Architecture\IdDeriver;
use Sillbeam\Core\Architecture\SourceInspector;
use Sillbeam\Core\I18n\CoreMessage;
use Sillbeam\Core\Module\Attribute\SillbeamModule;
use Sillbeam\Core\Project\ProjectDefinition;

/**
 * Discovers shared and Project-owned module descriptors and rejects ambiguous ownership.
 *
 * @internal
 */
final readonly class ModuleDiscovery
{
    public function __construct(private SourceInspector $inspector = new SourceInspector())
    {
    }

    /** @param array<class-string, ProjectDefinition> $projects
     * @return array<class-string, ModuleDefinition>
     */
    public function discover(DiscoveryOptions $options, array $projects): array
    {
        $modules = [];
        foreach ($this->inspector->descriptors($options, SillbeamModule::class) as $file => $class) {
            $directory = \dirname($file);
            foreach ($projects as $project) {
                if (str_starts_with($project->directory.'/', $directory.'/')) {
                    throw ArchitectureValidationException::fatalMessage('discovery.project_inside_module', new CoreMessage('finding.discovery.project_inside_module', ['project' => $project->class, 'module' => $class]), ['file' => $file, 'module' => $class]);
                }
            }
            $owner = null;
            $inProjectRoot = false;
            foreach ($options->projectRoots as $root) {
                $inProjectRoot = $inProjectRoot || $root->contains($file);
            }
            foreach ($projects as $project) {
                if (str_starts_with($directory.'/', $project->directory.'/') && $directory !== $project->directory) {
                    $owner = $project->class;
                }
            }
            if ($inProjectRoot && null === $owner) {
                throw ArchitectureValidationException::fatalMessage('discovery.owner_missing', new CoreMessage('finding.discovery.owner_missing', ['class' => $class]), ['file' => $file, 'module' => $class]);
            }
            $allowed = null !== $owner;
            foreach ($options->moduleRoots as $root) {
                $allowed = $allowed || $root->contains($file);
            }
            if (!$allowed) {
                throw ArchitectureValidationException::fatalMessage('discovery.module_boundary', new CoreMessage('finding.discovery.module_boundary', ['class' => $class]), ['file' => $file, 'module' => $class]);
            }
            foreach ($modules as $known) {
                if ($directory === $known->directory || str_starts_with($directory.'/', $known->directory.'/') || str_starts_with($known->directory.'/', $directory.'/')) {
                    throw ArchitectureValidationException::fatalMessage('discovery.module_overlap', new CoreMessage('finding.discovery.module_overlap', ['class' => $class, 'other' => $known->class]), ['file' => $file, 'module' => $class]);
                }
            }
            $metadata = $this->inspector->metadata($class, $file, SillbeamModule::class);
            $id = $metadata->id ?? IdDeriver::fromClass($class, 'Module', $file);
            $namespace = $options->namespaceForDirectory($directory);
            [$entities, $controllers] = $this->inspector->directoryGroups(
                $directory, [$options->entityPatterns, $options->controllerPatterns], options: $options,
            );
            $modules[$class] = new ModuleDefinition(
                id: $id,
                class: $class,
                description: $metadata->description,
                dependencies: $metadata->dependencies,
                enabled: true,
                directory: $directory,
                descriptorFile: $file,
                namespace: $namespace,
                entityDirectories: $entities,
                controllerDirectories: $controllers,
                projectClass: $owner,
            );
        }

        return $modules;
    }
}
