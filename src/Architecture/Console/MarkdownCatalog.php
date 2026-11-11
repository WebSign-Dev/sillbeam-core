<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture\Console;

use Sillbeam\Core\I18n\CoreTranslator;

/** @internal Markdown-only identity labels; never used as PHP class references. */
final readonly class MarkdownCatalog
{
    /** @var array<string, string> */
    public array $modules;
    /** @var array<string, string> */
    public array $projects;
    /** @var list<string> */
    public array $unresolvedModules;
    /** @var array<string, string> */
    public array $classes;

    /** @param array<string, mixed> $data */
    public function __construct(array $data, CoreTranslator $translator = new CoreTranslator())
    {
        $projects = $projectIds = [];
        foreach ($data['projects'] as $project) {
            $projects[$project['class']] = self::name($project['class'], 'Project');
            $projectIds[$project['class']] = $project['id'];
        }
        foreach ($data['modules'] as $module) {
            if (null !== ($owner = $module['project'] ?? null) && !isset($projects[$owner])) {
                $projects[$owner] = self::name($owner, 'Project').' ('.$translator->text('cli.label.unresolved').')';
                $projectIds[$owner] = 'unresolved';
            }
        }
        $this->projects = self::unique($projects, $projectIds);
        $modules = $moduleIds = [];
        foreach ($data['modules'] as $module) {
            $owner = $module['project'] ?? null;
            $modules[$module['class']] = (null === $owner ? '' : $this->projects[$owner].' / ').self::name($module['class'], 'Module');
            $moduleIds[$module['class']] = null === $owner ? 'shared:'.$module['id'] : 'project:'.($projectIds[$owner] ?? 'unresolved').':'.$module['id'];
        }
        $targets = [];
        foreach ($data['modules'] as $module) {
            $targets = [...$targets, ...$module['declared_dependencies']];
        }
        foreach ($data['projects'] as $project) {
            $targets = [...$targets, ...$project['declaredModules']];
        }
        $unresolved = [];
        foreach (array_unique($targets) as $target) {
            if (!isset($modules[$target])) {
                $unresolved[] = $target;
            }
        }
        sort($unresolved, \SORT_STRING);
        foreach ($unresolved as $index => $target) {
            $modules[$target] = self::name($target, 'Module').' ('.$translator->text('cli.label.unresolved').')';
            $moduleIds[$target] = 'unresolved:'.($index + 1);
        }
        $this->modules = self::unique($modules, $moduleIds);
        $this->unresolvedModules = $unresolved;
        $classes = $classIds = [];
        foreach ($data['findings'] as $finding) {
            $class = $finding['context']['class'] ?? null;
            if (\is_string($class) && !isset($this->modules[$class]) && !isset($this->projects[$class])) {
                $classes[$class] = self::name($class, '');
            }
        }
        $architectureLabels = array_fill_keys([...array_values($this->modules), ...array_values($this->projects)], true);
        foreach ($classes as $class => $name) {
            if (isset($architectureLabels[$name])) {
                $classes[$class] .= ' ('.$translator->text('cli.label.class').')';
            }
        }
        ksort($classes, \SORT_STRING);
        foreach (array_keys($classes) as $index => $class) {
            $classIds[$class] = 'class:'.($index + 1);
        }
        $this->classes = self::unique($classes, $classIds);
    }

    /** Shorten prose only. Executable resolution steps and originals bypass this method. */
    public function prose(string $text): string
    {
        $labels = [...$this->modules, ...$this->projects, ...$this->classes];
        if ([] === $labels) {
            return $text;
        }
        uksort($labels, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
        $pattern = '~(?<![a-zA-Z0-9_\\\\])(?:'.implode('|', array_map(static fn (string $class): string => preg_quote($class, '~'), array_keys($labels))).')(?![a-zA-Z0-9_\\\\]|::class)~';

        return preg_replace_callback($pattern, static fn (array $match): string => $labels[$match[0]], $text) ?? $text;
    }

    private static function name(string $class, string $suffix): string
    {
        $name = basename(str_replace('\\', '/', $class));

        return '' !== $suffix && str_ends_with($name, $suffix) && \strlen($name) > \strlen($suffix) ? substr($name, 0, -\strlen($suffix)) : $name;
    }

    /** @param array<string, string> $names
     * @param array<string, string> $qualifiers
     *
     * @return array<string, string>
     */
    private static function unique(array $names, array $qualifiers): array
    {
        ksort($names, \SORT_STRING);
        $counts = array_count_values($names);
        foreach ($names as $class => $name) {
            if ($counts[$name] > 1) {
                $names[$class] .= ' ['.$qualifiers[$class].']';
            }
        }
        $counts = array_count_values($names);
        $ordinals = [];
        foreach ($names as $class => $name) {
            if ($counts[$name] > 1) {
                $ordinals[$name] = ($ordinals[$name] ?? 0) + 1;
                $names[$class] .= ' #'.$ordinals[$name];
            }
        }

        return $names;
    }
}
