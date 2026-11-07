<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture\Console;

use Sillbeam\Core\Architecture\ArchitectureReport;
use Sillbeam\Core\I18n\CoreTranslator;

/** @internal Standalone Markdown using the same diagnostic recommendations as the CLI. */
final readonly class ReportRenderer
{
    public function __construct(private ArchitectureReport $report, private CoreTranslator $translator = new CoreTranslator())
    {
    }

    public function markdown(string $version, \DateTimeImmutable $checkedAt, bool $includeCouplings = false): string
    {
        $data = $this->report->data;
        $view = [] === $this->report->presentation ? ArchitecturePresentation::fromData($data) : $this->report->presentation;
        $catalog = new MarkdownCatalog($data);
        usort($data['modules'], static fn (array $a, array $b): int => strcmp($catalog->modules[$a['class']], $catalog->modules[$b['class']]));
        usort($data['projects'], static fn (array $a, array $b): int => strcmp($catalog->projects[$a['class']], $catalog->projects[$b['class']]));
        $errors = \count(array_filter($data['findings'], static fn (array $finding): bool => 'error' === $finding['severity']));
        $lines = [
            $this->translator->text('report.markdown.title'), '',
            $this->translator->text('report.markdown.version', ['version' => self::escape($version)]), '',
            $this->translator->text('report.markdown.date', ['date' => $checkedAt->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM)]), '',
            $this->translator->text('report.markdown.status', ['status' => self::escape($data['status'])]), '',
            $this->translator->text('report.markdown.complete', ['complete' => $this->translator->text($data['complete'] ? 'report.markdown.yes' : 'report.markdown.no')]), '',
            $this->translator->text('report.markdown.summary'), '',
            $this->translator->text('report.markdown.counts', ['findings' => \count($data['findings']), 'diagnoses' => \count($view['issues'])]), '',
            $this->translator->text('report.markdown.severity_counts', ['errors' => $errors, 'blockers' => \count($data['findings']) - $errors]), '',
            $this->translator->text('report.markdown.catalog_counts', ['modules' => \count($data['modules']), 'projects' => \count($data['projects'])]), '',
        ];
        if (!$data['complete']) {
            $lines[] = $this->translator->text('report.markdown.incomplete');
            $lines[] = '';
        }
        $lines[] = $this->translator->text('report.markdown.overview');
        $lines[] = '';
        if (!$data['valid']) {
            $lines[] = $this->translator->text('report.markdown.evidence');
            $lines[] = '';
        }
        if ([] === $data['modules'] && [] === $data['projects']) {
            $lines[] = $data['complete'] ? $this->translator->text('report.markdown.no_catalog') : $this->translator->text('report.markdown.unknown_catalog');
            $lines[] = '';
        }
        $lines = [...$lines, ...$this->overview($data, $catalog), $this->translator->text('report.markdown.diagnoses'), ''];
        if ([] === $view['issues']) {
            $lines[] = $this->translator->text('report.markdown.no_findings');
            $lines[] = '';
        } else {
            $lines[] = $this->translator->text('report.markdown.diagnosis_ids');
            $lines[] = '';
        }
        foreach ($view['issues'] as $index => $issue) {
            $lines = [...$lines, '### '.($index + 1).'. '.self::escape($issue['title']), '', self::escape(self::relation($issue, $catalog)), '', $this->translator->text('report.markdown.problem'), '', self::escape(self::description($issue, $issue['original_problem'] ?? $issue['problem'], $catalog)), ''];
            $lines[] = $this->translator->text('report.markdown.impact');
            $lines[] = '';
            $lines[] = self::escape(self::description($issue, $issue['original_impact'] ?? $issue['impact'], $catalog));
            $lines[] = '';
            $lines[] = $this->translator->text('report.markdown.steps');
            $lines[] = '';
            foreach (array_unique($issue['steps']) as $step) {
                $lines[] = '- '.self::escape($step);
            }
            $lines[] = '';
            if ([] !== $issue['references']) {
                $lines[] = $this->translator->text('report.markdown.locations');
                $lines[] = '';
                foreach ($issue['references'] as $reference) {
                    $lines[] = '- '.self::escape($reference['readable'] ?? $reference['file'].(\is_int($reference['line']) ? ':'.$reference['line'] : ''));
                }
                $lines[] = '';
            }
        }
        $lines = [...$lines, $this->translator->text('report.markdown.technical'), '', ...$this->identities($data, $catalog)];
        foreach ($view['issues'] as $index => $issue) {
            $lines[] = $this->translator->text('report.markdown.diagnosis', ['index' => $index + 1, 'title' => self::escape($issue['title'])]);
            $lines[] = '';
            $lines[] = self::escape($issue['detail']);
            $lines[] = '';
            foreach ($issue['originals'] as $number => $original) {
                $finding = $original['finding'];
                $lines[] = $this->translator->text('report.markdown.original', ['index' => $number + 1, 'rule' => self::escape($finding['rule']), 'severity' => self::escape($finding['severity'])]);
                $lines[] = '';
                $lines[] = self::escape($finding['message']);
                $lines[] = '';
                foreach ($finding['context'] as $key => $value) {
                    $lines[] = '- '.self::escape((string) $key).': '.self::escape(null === $value ? 'null' : (string) $value);
                }
                if ([] !== $finding['context']) {
                    $lines[] = '';
                }
            }
        }
        if ($includeCouplings && [] !== $view['couplings']) {
            $lines = [...$lines, $this->translator->text('report.markdown.couplings'), '', $this->translator->text('report.markdown.couplings_scope'), ''];
            foreach ($view['couplings'] as $coupling) {
                $source = $catalog->modules[$coupling['source'] ?? ''] ?? null;
                $relation = null === $source ? $coupling['relation'] : $source.' → '.$coupling['references'][0]['class'];
                $lines[] = '### '.self::escape($relation);
                $lines[] = '';
                foreach ($coupling['references'] as $reference) {
                    $lines[] = $this->translator->text('report.markdown.reference_evidence', ['class' => self::escape($reference['class']), 'location' => self::escape($reference['file'].':'.$reference['line']), 'target' => self::escape($reference['target_file'])]);
                }
                $lines[] = '';
            }
        }
        $lines[] = $this->translator->text('report.markdown.scope');
        $lines[] = '';

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $issue */
    private static function description(array $issue, string $text, MarkdownCatalog $catalog): string
    {
        $paths = [];
        foreach ($issue['references'] as $reference) {
            if (isset($reference['readable'])) {
                $path = $reference['readable'];
                $suffix = ':'.($reference['line'] ?? '');
                if (\is_int($reference['line']) && str_ends_with($path, $suffix)) {
                    $path = substr($path, 0, -\strlen($suffix));
                }
                $paths[$reference['file']] = $path;
            }
        }

        return strtr($catalog->prose($text), $paths);
    }

    /** @param array<string, mixed> $issue */
    private static function relation(array $issue, MarkdownCatalog $catalog): string
    {
        $label = static fn (string $class): string => $catalog->modules[$class] ?? $catalog->projects[$class] ?? $catalog->classes[$class] ?? $class;
        if ([] !== ($issue['relation_modules'] ?? [])) {
            return implode(' → ', array_map($label, $issue['relation_modules']));
        }
        $context = $issue['originals'][0]['finding']['context'] ?? [];
        $source = $context['module'] ?? $context['project'] ?? null;
        $target = $context['target'] ?? $context['class'] ?? null;
        if (\is_string($source) || \is_string($target)) {
            return (\is_string($source) ? $label($source) : 'Application').(\is_string($target) ? ' → '.$label($target) : '');
        }

        return $catalog->prose($issue['relation']);
    }

    /** @param array<string, mixed> $data
     * @return list<string>
     */
    private function overview(array $data, MarkdownCatalog $catalog): array
    {
        $lines = [];
        $details = [];
        if ([] !== $data['modules']) {
            $lines = [$this->translator->text('report.markdown.modules'), ''];
            $rows = $blocks = [];
            foreach ($data['modules'] as $module) {
                $name = $catalog->modules[$module['class']];
                $dependencies = array_values(array_map(static fn (string $class): string => $catalog->modules[$class], $module['declared_dependencies']));
                $cell = self::listSummary($dependencies, $this->translator->text('report.markdown.declared'));
                if (self::longList($dependencies)) {
                    $details = [...$details, $this->translator->text('report.markdown.deps_title', ['name' => self::escape($name)]), ''];
                    foreach ($dependencies as $dependency) {
                        $details[] = '- '.self::escape($dependency);
                    }
                    $details[] = '';
                }
                $scope = 'private' === $module['scope'] ? $this->translator->text('report.markdown.private') : $this->translator->text('report.markdown.shared');
                $state = !$data['valid'] || !$data['complete'] ? $this->translator->text('report.markdown.unknown') : (true === ($module['active'] ?? null) ? $this->translator->text('report.markdown.active') : (false === ($module['active'] ?? null) && \in_array($module['inactive_reason'] ?? null, ['disabled', 'unassigned'], true) ? ucfirst($module['inactive_reason']) : $this->translator->text('report.markdown.unknown')));
                if (mb_strlen($name) <= 48) {
                    $rows[] = '| '.self::escape($name).' | '.$scope.' | '.$state.' | '.self::escape($cell).' |';
                } else {
                    $blocks = [...$blocks, '#### '.self::escape($name), '', $this->translator->text('report.markdown.scope_label', ['scope' => $scope]), $this->translator->text('report.markdown.state_label', ['state' => $state]), $this->translator->text('report.markdown.deps_label', ['dependencies' => self::escape($cell)]), ''];
                }
            }
            if ([] !== $rows) {
                $lines = [...$lines, $this->translator->text('report.markdown.module_table'), '| --- | --- | --- | --- |', ...$rows, ''];
            }
            $lines = [...$lines, ...$blocks, ...$details];
        }
        if ([] !== $data['projects']) {
            $lines = [...$lines, $this->translator->text('report.markdown.projects'), ''];
            $rows = $blocks = [];
            $details = [];
            foreach ($data['projects'] as $project) {
                $name = $catalog->projects[$project['class']];
                $modules = array_values(array_map(static fn (string $class): string => $catalog->modules[$class], $project['declaredModules']));
                $hosts = $project['hosts'];
                if (mb_strlen($name) <= 48) {
                    $rows[] = '| '.self::escape($name).' | '.self::escape(self::listSummary($hosts, $this->translator->text('report.markdown.hosts_count'))).' | '.self::escape(self::listSummary($modules, $this->translator->text('report.markdown.declared'))).' |';
                } else {
                    $blocks = [...$blocks, '#### '.self::escape($name), '', $this->translator->text('report.markdown.host_label', ['hosts' => self::escape(self::listSummary($hosts, $this->translator->text('report.markdown.hosts_count')))]), $this->translator->text('report.markdown.modules_label', ['modules' => self::escape(self::listSummary($modules, $this->translator->text('report.markdown.declared')))]), ''];
                }
                foreach ([$this->translator->text('report.markdown.hosts_title') => $hosts, $this->translator->text('report.markdown.modules_title') => $modules] as $title => $items) {
                    if (self::longList($items)) {
                        $details = [...$details, $this->translator->text('report.markdown.detail_title', ['title' => $title, 'name' => self::escape($name)]), ''];
                        foreach ($items as $item) {
                            $details[] = '- '.self::escape($item);
                        }
                        $details[] = '';
                    }
                }
            }
            if ([] !== $rows) {
                $lines = [...$lines, $this->translator->text('report.markdown.project_table'), '| --- | --- | --- |', ...$rows, ''];
            }
            $lines = [...$lines, ...$blocks, ...$details];
        }

        return $lines;
    }

    /** @param list<string> $items */
    private static function longList(array $items): bool
    {
        return \count($items) > 3 || mb_strlen(implode(', ', $items)) > 60;
    }

    /** @param list<string> $items */
    private static function listSummary(array $items, string $unit): string
    {
        return [] === $items ? '—' : (self::longList($items) ? \count($items).' '.$unit : implode(', ', $items));
    }

    /** @param array<string, mixed> $data
     * @return list<string>
     */
    private function identities(array $data, MarkdownCatalog $catalog): array
    {
        $lines = [];
        if ([] !== $data['modules'] || [] !== $data['projects'] || [] !== $catalog->unresolvedModules) {
            $lines = [$this->translator->text('report.markdown.catalog_identity'), '', $this->translator->text('report.markdown.catalog_identity_info'), ''];
        }
        foreach ($data['modules'] as $module) {
            $lines = [...$lines, $this->translator->text('report.markdown.module_title', ['name' => self::escape($catalog->modules[$module['class']])]), '', $this->translator->text('report.markdown.module_class', ['class' => self::escape($module['class'])]), $this->translator->text('report.markdown.module_descriptor', ['path' => self::escape($module['descriptor'])])];
            if (null !== ($module['project'] ?? null)) {
                $lines[] = $this->translator->text('report.markdown.owner_label', ['project' => self::escape($module['project'])]);
            }
            $lines[] = '';
        }
        foreach ($data['projects'] as $project) {
            $lines = [...$lines, $this->translator->text('report.markdown.project_title', ['name' => self::escape($catalog->projects[$project['class']])]), '', $this->translator->text('report.markdown.project_class', ['class' => self::escape($project['class'])]), $this->translator->text('report.markdown.project_descriptor', ['path' => self::escape($project['descriptorFile'])]), ''];
        }
        foreach ($catalog->unresolvedModules as $class) {
            $lines = [...$lines, $this->translator->text('report.markdown.unresolved_title', ['name' => self::escape($catalog->modules[$class])]), '', $this->translator->text('report.markdown.reported_class', ['class' => self::escape($class)]), ''];
        }
        foreach ($catalog->classes as $class => $label) {
            $lines = [...$lines, $this->translator->text('report.markdown.reference_title', ['name' => self::escape($label)]), '', $this->translator->text('report.markdown.reported_class', ['class' => self::escape($class)]), ''];
        }
        if ([] === $lines && [] === $data['findings']) {
            $lines = [$this->translator->text('report.markdown.no_evidence'), ''];
        }

        return $lines;
    }

    private static function escape(string $text): string
    {
        if (1 !== preg_match('//u', $text)) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        $text = preg_replace('/[\p{Cc}]|(?:(?!\x{200D})\p{Cf})/u', '', $text) ?? '';
        // Escape ampersands first so source entities cannot become active Markdown/HTML.
        $text = str_replace('&', '&amp;', $text);

        $escaped = (string) preg_replace('/([\\\\`*_{}\[\]()<>#+|!~-])/', '\\\\$1', $text);

        return preg_replace_callback('/^([0-9]{1,9})\\.(?= )/', static fn (array $match): string => $match[1].'\\.', $escaped) ?? '';
    }
}
