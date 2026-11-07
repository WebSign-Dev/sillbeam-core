<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture\Console;

use Sillbeam\Core\Architecture\ArchitectureReport;
use Sillbeam\Core\I18n\CoreTranslator;

/**
 * Human labels and merged direct evidence over an existing ModuleInspection view.
 * Selection, activation, relevance and analysis remain owned by their existing providers.
 *
 * @internal
 */
final class ModuleInspectionPresentation
{
    /** @var array<string, string> */
    private array $labels = [];
    /** @var array<string, string> */
    private array $projects = [];
    /** @var array<string, string> */
    private array $paths = [];

    /** @var array<string, string> */
    private array $referenceLabels = [];
    /** @var array<string, string> */
    private array $titles = [];
    /** @var list<string> */
    private array $owners = [];
    /** @var array<string, string> */
    private array $cautions = [];

    public function __construct(private readonly ArchitectureReport $report, private readonly CoreTranslator $translator = new CoreTranslator())
    {
        $catalog = $report->data['modules'];
        $projectCatalog = $report->data['projects'];
        $this->owners = array_values(array_unique(array_merge(array_filter(array_column($catalog, 'project')), array_column($projectCatalog, 'class'))));
        foreach ($projectCatalog as $project) {
            $collisions = array_filter($projectCatalog, static fn (array $other): bool => 0 === strcasecmp($other['id'], $project['id']));
            $this->projects[$project['class']] = $project['id'].(\count($collisions) > 1 ? ' ['.self::suffix($project['class'], array_column($collisions, 'class')).']' : '');
        }
        foreach ($catalog as $module) {
            $collisions = array_filter($catalog, static fn (array $other): bool => 0 === strcasecmp($other['id'], $module['id']));
            $label = $module['id'];
            if (\count($collisions) > 1) {
                $scope = 'shared' === $module['scope'] ? $this->translator->text('cli.graph.label.scope_shared') : $this->translator->text('cli.inspect.private_owner', ['project' => $this->project($module['project'])]);
                $sameOwner = array_filter($collisions, static fn (array $other): bool => $other['scope'] === $module['scope'] && $other['project'] === $module['project']);
                if (\count($sameOwner) > 1) {
                    $scope .= '; '.self::suffix($module['class'], array_column($sameOwner, 'class'));
                }
                $label .= ' ['.$scope.']';
            }
            $this->labels[$module['class']] = $label;
        }
        $unresolved = [];
        foreach ($catalog as $module) {
            foreach (array_merge($module['declared_dependencies'], $module['observed_dependencies']) as $class) {
                if (!isset($this->labels[$class])) {
                    $unresolved[$class] = true;
                }
            }
        }
        foreach (array_keys($unresolved) as $class) {
            $this->labels[$class] = self::suffix($class, array_keys($unresolved)).' [unresolved]';
        }
        foreach ($catalog as $module) {
            foreach ($module['references'] as $reference) {
                $this->referenceLabels[$reference['class']] = $this->label($reference['module']).' / '.basename(str_replace('\\', '/', $reference['class']));
            }
        }
        foreach ($report->presentation['issues'] ?? [] as $issue) {
            foreach ($issue['originals'] ?? [] as $original) {
                $key = self::findingKey($original['finding']);
                $this->titles[$key] = $issue['title'];
                if (\is_string($issue['caution'] ?? null)) {
                    $this->cautions[$key] = $issue['caution'];
                }
            }
            foreach ($issue['references'] ?? [] as $reference) {
                $readable = $reference['readable'];
                $suffix = null === $reference['line'] ? '' : ':'.$reference['line'];
                if ('' !== $suffix && str_ends_with($readable, $suffix)) {
                    $readable = substr($readable, 0, -\strlen($suffix));
                }
                $file = $reference['file'];
                if ($readable === $file || $readable === ltrim($file, '/') || preg_match('~^(?:/|[A-Za-z]:[\\\\/])~', $readable)) {
                    $readable = basename(str_replace('\\', '/', $file));
                }
                $this->paths[$file] = $readable;
            }
        }
        foreach ($report->data['findings'] as $finding) {
            $file = $finding['context']['file'] ?? null;
            if (\is_string($file) && !isset($this->paths[$file])) {
                // Without a known root, name the real basename, never invent a relative path.
                $this->paths[$file] = basename(str_replace('\\', '/', $file));
            }
        }
    }

    public function label(string $class): string
    {
        return $this->labels[$class] ?? $class;
    }

    public function project(?string $class): string
    {
        return null === $class ? $this->translator->text('cli.inspect.owner_unknown') : ($this->projects[$class] ?? $this->translator->text('cli.inspect.owner_missing', ['class' => self::suffix($class, array_values(array_unique([...$this->owners, $class])))]));
    }

    /**
     * Deduplicates by descriptor identity, classifying only existing positive evidence.
     * Missing observations remain unverified when the source's evidence is incomplete.
     *
     * @param array<string, mixed> $view selected ModuleInspection result
     *
     * @return list<array{class: string, label: string, evidence: string, problem: bool}>
     */
    public function relationships(array $view, bool $incoming = false): array
    {
        $declared = $incoming ? $view['incoming'] : $view['module']['declared_dependencies'];
        $observed = $incoming ? array_column($view['incoming_references'], 'source') : $view['module']['observed_dependencies'];
        $classes = array_values(array_unique(array_merge($declared, $observed)));
        $rows = [];
        foreach ($classes as $class) {
            $isDeclared = \in_array($class, $declared, true);
            $isObserved = \in_array($class, $observed, true);
            $complete = $incoming ? ($this->report->presentation['module_inspection']['configuration'][$class]['references_complete'] ?? false) : $view['configuration']['references_complete'];
            // Completeness belongs to the source, not the selected target or unrelated modules.
            $complete = $this->report->data['complete'] && $complete;
            $evidence = $isObserved ? ($isDeclared ? $this->translator->text('cli.inspect.evidence.both') : $this->translator->text('cli.inspect.evidence.observed')) : ($complete ? $this->translator->text('cli.inspect.evidence.declared') : $this->translator->text('cli.inspect.evidence.unverified'));
            $rows[] = ['class' => $class, 'label' => $this->label($class), 'evidence' => $evidence, 'problem' => $isObserved && !$isDeclared];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['label'], $a['class']] <=> [$b['label'], $b['class']]);

        return $rows;
    }

    /** Uses existing relative-path presentation and collision-aware catalog labels. */
    public function readable(string $text): string
    {
        $labels = $this->labels + $this->projects + $this->referenceLabels;
        if ([] !== $labels) {
            uksort($labels, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
            $pattern = '~(?<![a-zA-Z0-9_\\\\])(?:'.implode('|', array_map(static fn (string $class): string => preg_quote($class, '~'), array_keys($labels))).')(?![a-zA-Z0-9_\\\\])~';
            $text = preg_replace_callback($pattern, static fn (array $match): string => $labels[$match[0]], $text) ?? $text;
        }

        return strtr($text, $this->paths);
    }

    /** Reuses existing diagnosis titles; raw/fatal details remain visible when no title is available.
     * @param array<string, mixed> $finding
     */
    public function problem(array $finding): string
    {
        $title = $this->titles[self::findingKey($finding)] ?? null;
        if (null === $title || 'fatal' === $finding['severity']) {
            return $this->readable($finding['message']);
        }
        $context = $finding['context'];
        $source = $context['module'] ?? $context['project'] ?? null;
        $target = $context['target'] ?? null;
        $relation = \is_string($source) ? $this->readable($source) : '';
        if (\is_string($target)) {
            $relation .= ('' === $relation ? '' : ' → ').$this->readable($target);
        }
        if ('dependency.cycle' === $finding['rule']) {
            $relation = $this->translator->text('cli.inspect.participants', ['cycle' => $this->readable((string) ($context['cycle'] ?? $this->translator->text('cli.inspect.owner_unknown')))]);
        }
        $class = $context['class'] ?? null;
        if (\is_string($class) && null === $target) {
            $relation .= ('' === $relation ? '' : '; ').$this->translator->text('cli.inspect.reference_class', ['class' => $this->referenceLabels[$class] ?? basename(str_replace('\\', '/', $class))]);
        }

        foreach (['host', 'hosts', 'key', 'override', 'id'] as $key) {
            $value = $context[$key] ?? null;
            if (\is_string($value) || \is_array($value)) {
                $relation .= ('' === $relation ? '' : '; ').$key.' '.(\is_string($value) ? $value : json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE));
            }
        }

        return $title.('' === $relation ? '' : ': '.$relation).'.';
    }

    /** Existing architecture cautions are evidence, not a new dependency assessment.
     * @param array<string, mixed> $finding
     */
    public function caution(array $finding): ?string
    {
        $text = $this->cautions[self::findingKey($finding)] ?? null;

        return null === $text ? null : $this->readable($text);
    }

    /** @param array<string, mixed> $finding */
    private static function findingKey(array $finding): string
    {
        $context = $finding['context'];
        unset($context['suggestion']);
        ksort($context);

        return serialize([$finding['rule'], $finding['message'], $finding['severity'], $context]);
    }

    /** @param array<string, mixed> $finding */
    public function location(array $finding): ?string
    {
        $file = $finding['context']['file'] ?? null;

        return \is_string($file) ? ($this->paths[$file] ?? basename(str_replace('\\', '/', $file))).(isset($finding['context']['line']) ? ':'.$finding['context']['line'] : '') : null;
    }

    /** @param list<string> $classes */
    private static function suffix(string $class, array $classes): string
    {
        $parts = explode('\\', $class);
        for ($length = 1; $length <= \count($parts); ++$length) {
            $suffix = implode('\\', \array_slice($parts, -$length));
            if (1 === \count(array_filter($classes, static fn (string $other): bool => $other === $suffix || str_ends_with($other, '\\'.$suffix)))) {
                return $suffix;
            }
        }

        return $class;
    }
}
