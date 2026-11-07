<?php

declare(strict_types=1);

namespace Sillbeam\Core\Project;

use Sillbeam\Core\Architecture\ArchitectureValidationException;
use Sillbeam\Core\Architecture\DiscoveryOptions;
use Sillbeam\Core\Architecture\IdDeriver;
use Sillbeam\Core\Architecture\SourceInspector;
use Sillbeam\Core\I18n\CoreMessage;
use Sillbeam\Core\Project\Attribute\SillbeamProject;

/**
 * Discovers Project descriptors and rejects overlapping or misplaced Project areas.
 *
 * @internal
 */
final readonly class ProjectDiscovery
{
    public function __construct(private SourceInspector $inspector = new SourceInspector())
    {
    }

    /** @return array<class-string, ProjectDefinition> */
    public function discover(DiscoveryOptions $options): array
    {
        $projects = [];
        foreach ($this->inspector->descriptors($options, SillbeamProject::class) as $file => $class) {
            $allowed = false;
            foreach ($options->projectRoots as $root) {
                $allowed = $allowed || $root->contains($file);
            }
            if (!$allowed) {
                throw ArchitectureValidationException::fatalMessage('discovery.project_boundary', new CoreMessage('finding.discovery.project_boundary', ['class' => $class]), ['file' => $file, 'project' => $class]);
            }
            $metadata = $this->inspector->metadata($class, $file, SillbeamProject::class);
            $directory = \dirname($file);
            foreach ($projects as $known) {
                if ($directory === $known->directory || str_starts_with($directory.'/', $known->directory.'/') || str_starts_with($known->directory.'/', $directory.'/')) {
                    throw ArchitectureValidationException::fatalMessage('discovery.project_overlap', new CoreMessage('finding.discovery.project_overlap', ['class' => $class, 'other' => $known->class]), ['file' => $file, 'project' => $class]);
                }
            }
            $projects[$class] = new ProjectDefinition(
                id: $metadata->id ?? IdDeriver::fromClass($class, 'Project', $file),
                class: $class,
                description: $metadata->description,
                declaredModules: $metadata->modules,
                modules: [],
                directory: $directory,
                descriptorFile: $file,
                namespace: $options->namespaceForDirectory($directory),
                hosts: [],
                controllerDirectories: [],
                entityDirectories: [],
            );
        }

        return $projects;
    }
}
