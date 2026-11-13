<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture;

use Composer\Autoload\ClassLoader;
use Sillbeam\Core\I18n\CoreMessage;

/**
 * Checks static module references against declared dependencies, privacy and activation.
 *
 * Uses the source index and bounded Composer lookups; dynamic names, PHPDoc and unused
 * imports are not dependency edges. Observed evidence never activates modules.
 *
 * @internal
 */
final readonly class CodeDependencyAnalyzer
{
    public function __construct(private SourceInspector $inspector = new SourceInspector())
    {
    }

    /**
     * Runs static reference checks and rejects all collected violations.
     *
     * @return array<class-string, list<array{module: class-string, class: string, file: string, line: int}>>
     *
     * @throws ArchitectureValidationException if any dependency or source finding is collected
     */
    public function analyze(DiscoveryResult $discovery, CompiledArchitecture $architecture, DiscoveryOptions $options): array
    {
        $findings = new ArchitectureFindings();
        $references = $this->collect($discovery, $architecture, $options, $findings);
        (new ArchitectureAnalysisResult($findings->all(), references: $references))->throwIfInvalid();

        return $references;
    }

    /**
     * Collects module edges and application couplings without activating referenced modules.
     *
     * Mutates the findings collector and replaces applicationReferences with per-module evidence.
     *
     * @internal collect without publishing an invalid graph
     *
     * @param array<class-string, list<array{class: string, file: string, line: int, target_file: string}>> $applicationReferences
     *
     * @return array<class-string, list<array{module: class-string, class: string, file: string, line: int}>>
     *
     * @throws ArchitectureValidationException if duplicate source symbols make ownership ambiguous
     */
    public function collect(DiscoveryResult $discovery, CompiledArchitecture $architecture, DiscoveryOptions $options, ArchitectureFindings $findings, array &$applicationReferences = []): array
    {
        $files = $this->inspector->files($options);
        $owners = [];
        $symbols = [];
        $knownClasses = [];
        $classFiles = [];
        $applicationReferences = array_fill_keys(array_keys($discovery->modules), []);
        $references = array_fill_keys(array_keys($discovery->modules), []);
        $active = array_fill_keys($architecture->usedModules(), true);
        $moduleDirectories = new AreaDirectoryMap(array_column($discovery->modules, null, 'directory'));
        $projectDirectories = new AreaDirectoryMap(array_column($discovery->projects, null, 'directory'));
        foreach ($files as $file) {
            foreach ($file->classes as $class) {
                $knownClasses[strtolower($class)] = true;
                $classFiles[strtolower($class)] = ['class' => $class, 'file' => $file->path];
            }
            $owner = $moduleDirectories->owner($file->path);
            if (null === $owner) {
                continue;
            }
            $owners[$file->path] = $owner;
            foreach ($file->classes as $class) {
                $key = strtolower($class);
                if (isset($symbols[$key])) {
                    throw ArchitectureValidationException::fatalMessage('source.duplicate_class', new CoreMessage('finding.source.duplicate_class', ['class' => $class]), ['class' => $class, 'file' => $file->path, 'module' => $owner->class]);
                }
                $symbols[$key] = $owner;
            }
        }
        $resolved = [];
        foreach ($files as $file) {
            $source = $owners[$file->path] ?? null;
            $context = null === $source ? null : ['module' => $source->class, 'project' => $source->projectClass];
            $directory = $source?->directory;
            if (null === $context) {
                $project = $projectDirectories->owner($file->path);
                if (null !== $project) {
                    $context = ['project' => $project->class];
                    $directory = $project->directory;
                }
            }
            if (null === $context || null === $directory || PathMatcher::matches(substr($file->path, \strlen($directory) + 1), ['Tests/**', '**/Tests/**', 'Fixtures/**', '**/Fixtures/**'])) {
                continue;
            }
            $missing = [];
            foreach ($file->references as $reference) {
                // PHP permits absent attribute classes until their metadata is instantiated.
                if ('attribute' === ($reference['kind'] ?? null)) {
                    continue;
                }
                $key = strtolower($reference['class']);
                $resolved[$key] ??= isset($knownClasses[$key]) || !$this->applicationClass($reference['class'], $options) || $this->resolvesClass($reference['class'], $options);
                $locationKey = $key.':'.$reference['line'];
                if (!$resolved[$key] && !isset($missing[$locationKey])) {
                    $missing[$locationKey] = true;
                    $findings->add(ArchitectureFinding::fromMessage('code.unknown_class', new CoreMessage('finding.code.unknown_class', ['class' => $reference['class']]), [
                        ...$context, 'class' => $reference['class'], 'file' => $file->path, 'line' => $reference['line'],
                    ]));
                }
            }
        }
        foreach ($files as $file) {
            $source = $owners[$file->path] ?? null;
            if (null === $source) {
                continue;
            }
            $seen = [];
            foreach ($file->references as $reference) {
                $target = $symbols[strtolower($reference['class'])] ?? null;
                if (null === $target && isset($classFiles[strtolower($reference['class'])])) {
                    $application = $classFiles[strtolower($reference['class'])];
                    $evidence = ['class' => $application['class'], 'file' => $file->path, 'line' => $reference['line'], 'target_file' => $application['file']];
                    $key = serialize($evidence);
                    if (!isset($seen[$key])) {
                        $applicationReferences[$source->class][] = $evidence;
                        $seen[$key] = true;
                    }
                }
                if (null === $target || $source->class === $target->class) {
                    continue;
                }
                $evidence = ['module' => $target->class, 'class' => $reference['class'], 'file' => $file->path, 'line' => $reference['line']];
                $key = serialize([...$evidence, 'class' => strtolower($reference['class'])]);
                if (isset($seen[$key])) {
                    continue;
                }
                $references[$source->class][] = $evidence;
                $seen[$key] = true;
                if (!isset($active[$source->class])) {
                    continue;
                }
                $referenceText = ['file' => $file->path, 'line' => $reference['line'], 'source' => $source->id, 'class' => $reference['class'], 'target' => $target->id];
                $context = ['module' => $source->class, 'project' => $source->projectClass, 'target' => $target->class, 'class' => $reference['class'], 'file' => $file->path, 'line' => $reference['line']];
                if ($target->isPrivate() && (!$source->isPrivate() || $source->projectClass !== $target->projectClass)) {
                    $findings->add(ArchitectureFinding::fromMessage('code.private', new CoreMessage('finding.code.private', $referenceText), $context));
                } elseif (!isset($active[$target->class])) {
                    $findings->add(ArchitectureFinding::fromMessage('code.inactive', new CoreMessage('finding.code.inactive', $referenceText), $context));
                } elseif (!\in_array($target->class, $source->dependencies, true)) {
                    $findings->add(ArchitectureFinding::fromMessage('code.undeclared_dependency', new CoreMessage('finding.code.undeclared_dependency', $referenceText), $context));
                }
            }
        }

        return $references;
    }

    private function applicationClass(string $class, DiscoveryOptions $options): bool
    {
        $roots = [...$options->roots(), ...$options->applicationRoots];
        $specificity = 0;
        foreach ($roots as $root) {
            if (str_starts_with(strtolower($class), strtolower($root->namespace))) {
                $specificity = max($specificity, \strlen($root->namespace));
            }
        }
        if (0 === $specificity) {
            return false;
        }
        // A package can own a more specific prefix below an application's broad prefix.
        $runtimeSpecificity = $specificity;
        $owned = true;
        foreach (spl_autoload_functions() as $autoload) {
            if (!\is_array($autoload) || !$autoload[0] instanceof ClassLoader) {
                continue;
            }
            foreach ($autoload[0]->getPrefixesPsr4() as $prefix => $paths) {
                if (\strlen($prefix) <= $specificity || \strlen($prefix) < $runtimeSpecificity || !str_starts_with(strtolower($class), strtolower($prefix))) {
                    continue;
                }
                if (\strlen($prefix) > $runtimeSpecificity) {
                    $runtimeSpecificity = \strlen($prefix);
                    $owned = true;
                }
                foreach ($paths as $path) {
                    $local = false;
                    foreach ($roots as $root) {
                        if (str_starts_with(strtolower($prefix), strtolower($root->namespace))) {
                            $expected = $root->path.'/'.str_replace('\\', '/', substr($prefix, \strlen($root->namespace)));
                            if (FilesystemPath::normalize(realpath($path) ?: $path) === FilesystemPath::normalize(realpath($expected) ?: $expected)) {
                                $local = true;
                                break;
                            }
                        }
                    }
                    if (!$local) {
                        $owned = false;
                    }
                }
            }
        }

        return $owned;
    }

    private function resolvesClass(string $class, DiscoveryOptions $options): bool
    {
        if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
            return true;
        }
        foreach ([...$options->roots(), ...$options->applicationRoots] as $root) {
            if (str_starts_with(strtolower($class), strtolower($root->namespace))) {
                $file = $root->path.'/'.str_replace('\\', '/', substr($class, \strlen($root->namespace))).'.php';
                if (is_file($file)) {
                    return true;
                }
            }
        }
        // Resolve Composer paths without loading application/vendor code or booting services.
        foreach (spl_autoload_functions() as $autoload) {
            if (\is_array($autoload) && $autoload[0] instanceof ClassLoader) {
                $file = $autoload[0]->findFile($class);
                if (false !== $file && is_file($file)) {
                    return true;
                }
            }
        }

        return false;
    }
}
