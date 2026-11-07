<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture;

use Sillbeam\Core\I18n\CoreError;
use Sillbeam\Core\Module\Attribute\SillbeamModule;
use Sillbeam\Core\Project\Attribute\SillbeamProject;

/** @internal Minimal descriptor creation shared by the two console commands. */
final readonly class DescriptorCreator
{
    public function __construct(private DiscoveryOptions $options)
    {
    }

    /**
     * Validates ownership and target paths before creating a directory and an exclusive descriptor file.
     *
     * Returns the new file path. Loading existing descriptors can execute application code.
     * I/O failure after directory creation can leave a partial target; no rollback is performed.
     *
     * @param string      $name    ASCII base name without the Module/Project suffix
     * @param bool        $project whether to create a Project instead of a module
     * @param string|null $owner   existing Project ID, class or directory name for a private module
     *
     * @throws \InvalidArgumentException       if the name, owner, boundary or target is invalid
     * @throws \RuntimeException               if creating or writing the target fails
     * @throws ArchitectureValidationException if existing discovery fails
     */
    public function create(string $name, bool $project = false, ?string $owner = null): string
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/D', $name)) {
            throw CoreError::create(\InvalidArgumentException::class, 'cli.make.error.name');
        }
        // Only the first ASCII letter changes; preserve the rest of the base name.
        $name = ucfirst($name);
        $suffix = $project ? 'Project' : 'Module';
        if (str_ends_with($name, $suffix)) {
            throw CoreError::create(\InvalidArgumentException::class, 'cli.make.error.suffix', ['suffix' => $suffix]);
        }
        $roots = $project ? $this->options->projectRoots : $this->options->moduleRoots;
        if (null === $owner) {
            if (1 !== \count($roots)) {
                throw CoreError::create(\InvalidArgumentException::class, 'cli.make.error.root', ['type' => $project ? 'projects' : 'modules']);
            }
            $directory = $roots[0]->path.'/'.$name;
        } else {
            $matches = [];
            foreach ((new ArchitectureDiscovery())->discover($this->options)->projects as $definition) {
                if ($owner === $definition->id || $owner === basename($definition->directory) || $owner === $definition->class) {
                    $matches[] = $definition;
                }
            }
            if (1 !== \count($matches)) {
                throw CoreError::create(\InvalidArgumentException::class, 'cli.make.error.owner', ['owner' => $owner]);
            }
            $directory = $matches[0]->directory.'/Module/'.$name;
        }
        $file = $directory.'/'.$name.$suffix.'.php';
        for ($path = $file; '/' !== $path && '.' !== $path; $path = \dirname($path)) {
            if (is_link($path) || preg_match('~(?:^|/)\.\.?(/|$)~', $path)) {
                throw CoreError::create(\InvalidArgumentException::class, 'cli.make.error.unsafe', ['path' => $path]);
            }
            if ($this->options->excluded($path)) {
                throw CoreError::create(\InvalidArgumentException::class, 'cli.make.error.excluded', ['path' => $path]);
            }
        }
        if (file_exists($directory) || is_link($directory)) {
            throw CoreError::create(\InvalidArgumentException::class, 'cli.make.error.exists', ['path' => $directory]);
        }
        $namespace = $this->options->namespaceForDirectory($directory);
        $attribute = $project ? SillbeamProject::class : SillbeamModule::class;
        $shortAttribute = 'Sillbeam'.$suffix;
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nnamespace $namespace;\n\nuse $attribute;\n\n#[$shortAttribute]\nfinal class $name$suffix\n{\n}\n";
        // Validate namespace segments (including PHP keywords) before touching the filesystem.
        try {
            (new \PhpParser\ParserFactory())->createForNewestSupportedVersion()->parse($contents);
        } catch (\PhpParser\Error $error) {
            throw CoreError::create(\InvalidArgumentException::class, 'cli.make.error.descriptor', ['error' => $error->getMessage()], previous: $error);
        }
        $discovery = (new ArchitectureDiscovery())->discover($this->options);
        foreach ($discovery->modules as $module) {
            if ((new DiscoveryRoot($module->directory, ''))->contains($directory) || (new DiscoveryRoot($directory, ''))->contains($module->directory)) {
                throw CoreError::create(\InvalidArgumentException::class, 'cli.make.error.module_overlap', ['path' => $module->directory]);
            }
        }
        foreach ($discovery->projects as $definition) {
            if (($project || null === $owner) && ((new DiscoveryRoot($definition->directory, ''))->contains($directory) || (new DiscoveryRoot($directory, ''))->contains($definition->directory))) {
                throw CoreError::create(\InvalidArgumentException::class, 'cli.make.error.project_overlap', ['path' => $definition->directory]);
            }
        }
        if (!$project && null === $owner) {
            foreach ($this->options->projectRoots as $root) {
                if ($root->contains($directory)) {
                    throw CoreError::create(\InvalidArgumentException::class, 'cli.make.error.in_project');
                }
            }
        }
        if (!mkdir($directory, 0777, true)) {
            throw CoreError::create(\RuntimeException::class, 'cli.make.error.directory', ['path' => $directory]);
        }
        $handle = fopen($file, 'x');
        if (false === $handle) {
            throw CoreError::create(\RuntimeException::class, 'cli.make.error.create', ['path' => $file]);
        }
        try {
            if (\strlen($contents) !== fwrite($handle, $contents)) {
                throw CoreError::create(\RuntimeException::class, 'cli.make.error.write', ['path' => $file]);
            }
        } finally {
            fclose($handle);
        }

        return $file;
    }
}
