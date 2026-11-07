<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture\Console;

use Sillbeam\Core\Architecture\ArchitectureReport;
use Sillbeam\Core\Console\Style\ConsoleStyle;
use Sillbeam\Core\I18n\CoreError;
use Sillbeam\Core\I18n\CoreTranslator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** @internal Text inspection of one module from the existing shared architecture report. */
#[AsCommand(name: 'sillbeam:inspect:module', description: 'Inspect one module, its activation and direct dependency evidence.')]
final class InspectModuleCommand extends Command
{
    /** @param ArchitectureReport|\Closure(): ArchitectureReport $report */
    public function __construct(private readonly ArchitectureReport|\Closure $report, private readonly CoreTranslator $translator = new CoreTranslator())
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('module', InputArgument::REQUIRED, $this->translator->text('cli.inspect.argument'));
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, $this->translator->text('cli.inspect.project'));
        $this->setHelp($this->translator->text('cli.inspect.help'));
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (\Symfony\Component\Console\Exception\ExceptionInterface $error) {
            (new ConsoleStyle($output))->error(CoreError::render($error, $this->translator));

            return 2;
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new ConsoleStyle($output);
        $identifier = $input->getArgument('module');
        $project = $input->getOption('project');
        if (!\is_string($identifier) || '' === trim($identifier) || (null !== $project && (!\is_string($project) || '' === trim($project)))) {
            $style->error($this->translator->text('cli.inspect.invalid'));

            return 2;
        }
        $report = ArchitectureReport::collect($this->report);
        $style->heading($this->translator->text('cli.inspect.heading'));
        try {
            $view = (new ModuleInspection($report))->select($identifier, $project);
        } catch (\InvalidArgumentException $error) {
            $style->error(CoreError::render($error, $this->translator));
            if (!$report->data['complete']) {
                $this->findings($style, $report->data['findings'], new ModuleInspectionPresentation($report, $this->translator));
                if ($output->isVerbose()) {
                    $this->originals($style, $report->data['findings']);
                }
            }

            return 2;
        }
        $module = $view['module'];
        $presentation = new ModuleInspectionPresentation($report, $this->translator);
        $outgoing = $presentation->relationships($view);
        $incoming = $presentation->relationships($view, true);
        $style->text($this->translator->text('cli.inspect.module_label', ['module' => $presentation->label($module['class']), 'state' => ucfirst($view['state'])]), style: 'options=bold');
        $style->text($this->translator->text('cli.inspect.scope_line', ['scope' => ucfirst($module['scope']), 'owner' => 'private' === $module['scope'] ? $this->translator->text('cli.inspect.owner_suffix', ['project' => $presentation->project($module['project'])]) : '']));
        if (null !== $view['project']) {
            $style->text($this->translator->text('cli.inspect.project_context', ['project' => $presentation->project($view['project']['class'])]));
            if ($view['active'] !== $module['active']) {
                $style->note($this->translator->text('cli.inspect.global_state', ['state' => null === $module['active'] ? 'unknown' : ($module['active'] ? 'active' : 'inactive')]));
            }
        }
        $style->text($this->translator->text('cli.inspect.architecture', ['status' => ucfirst($report->data['status'])]));
        $style->text($this->translator->text('cli.inspect.relationships', ['outgoing' => \count($outgoing), 'incoming' => \count($incoming)]));
        if (null === $view['active']) {
            $style->note($this->translator->text('cli.inspect.activation_unknown'));
        }
        if (!$report->data['complete'] || !$view['configuration']['references_complete'] || !$view['references_complete']) {
            $style->note($this->translator->text('cli.inspect.references_incomplete'));
        }
        if (!$report->data['complete']) {
            $style->note($this->translator->text('cli.inspect.catalog_incomplete'));
        }
        $this->relationships($style, $this->translator->text('cli.inspect.depends_on'), $outgoing);
        $this->relationships($style, $this->translator->text('cli.inspect.used_by'), $incoming);
        $style->rule();
        $style->text($this->translator->text('cli.inspect.issues'), style: 'options=bold');
        $this->findings($style, $view['findings'], $presentation);
        if ([] === $view['findings']) {
            $message = $this->translator->text('cli.inspect.no_issues');
            if ($report->data['valid'] && $report->data['complete'] && [] === $view['blockers'] && null !== $view['active'] && $view['configuration']['references_complete'] && $view['references_complete'] && !array_any(array_merge($outgoing, $incoming), static fn (array $row): bool => $row['problem'])) {
                $style->success($message);
            } else {
                $style->text($message);
            }
        }
        if ([] !== $view['blockers']) {
            $style->text($this->translator->text('cli.inspect.blockers'), style: 'options=bold');
            $this->findings($style, $view['blockers'], $presentation);
        }
        if ($output->isVerbose()) {
            $this->technical($style, $view);
        }
        if (0 !== $report->exitCode()) {
            $style->note($this->translator->text('cli.inspect.whole', ['status' => $report->data['status']]));
        }

        return $report->exitCode();
    }

    /** @param list<array{class: string, label: string, evidence: string, problem: bool}> $rows */
    private function relationships(ConsoleStyle $style, string $title, array $rows): void
    {
        $style->rule();
        $style->text($title, style: 'options=bold');
        if ([] === $rows) {
            $style->text($this->translator->text('cli.inspect.no_evidence'));
        }
        foreach ($rows as $row) {
            $text = $row['label'].' — '.$row['evidence'];
            if ($row['problem']) {
                $style->error($text);
            } else {
                $style->text($text, '    ');
            }
        }
    }

    /** Technical evidence is additive; no effective state is derived from configuration inputs.
     * @param array<string, mixed> $view
     */
    private function technical(ConsoleStyle $style, array $view): void
    {
        $module = $view['module'];
        $config = $view['configuration'];
        $style->rule();
        $style->text($this->translator->text('cli.inspect.technical'), style: 'options=bold');
        foreach ([
            'ID' => $module['id'], $this->translator->text('cli.inspect.descriptor_class') => $module['class'], $this->translator->text('cli.inspect.descriptor_file') => $module['descriptor'],
            $this->translator->text('cli.inspect.scope') => $module['scope'], $this->translator->text('cli.inspect.owner') => $view['owner']['id'] ?? ('shared' === $module['scope'] ? $this->translator->text('cli.inspect.none_shared') : 'unknown'),
            $this->translator->text('cli.inspect.configuration_key') => $config['key'] ?? $this->translator->text('cli.inspect.owner_unknown'),
            $this->translator->text('cli.inspect.override') => null === $config['key'] ? $this->translator->text('cli.inspect.owner_unknown') : (null === $config['source'] ? $this->translator->text('cli.inspect.none_override') : $this->translator->text('cli.inspect.configured_key', ['key' => $config['source']])),
            $this->translator->text('cli.inspect.enabled') => self::boolean($module['enabled']), $this->translator->text('cli.inspect.active') => self::boolean($view['active']),
            $this->translator->text('cli.inspect.state') => $view['state'], $this->translator->text('cli.inspect.global_active') => self::boolean($module['active']),
            $this->translator->text('cli.inspect.activation_reasons') => [] === ($module['activation_reasons'] ?? []) ? $this->translator->text('cli.inspect.none_recorded') : implode(', ', $module['activation_reasons']),
        ] as $label => $value) {
            $style->text($label.': '.$value);
        }
        if (null !== $config['enabled_override']) {
            $style->text($this->translator->text('cli.inspect.enabled_override', ['value' => self::boolean($config['enabled_override'])]));
        }
        if (null !== $view['owner']) {
            $style->text($this->translator->text('cli.inspect.owner_class', ['class' => $view['owner']['class']]));
        }
        if (null !== $view['project']) {
            $style->text($this->translator->text('cli.inspect.inspection_project', ['id' => $view['project']['id']]));
            $style->text($this->translator->text('cli.inspect.project_class', ['class' => $view['project']['class']]));
        }
        foreach ($view['project_states'] as $state) {
            $style->text($this->translator->text('cli.inspect.project_state', ['id' => $state['id'], 'state' => $state['state'], 'class' => $state['class']]));
        }
        $this->section($style, $this->translator->text('cli.inspect.declared'), $module['declared_dependencies'], $this->translator->text('cli.inspect.none'));
        $this->section($style, $this->translator->text('cli.inspect.observed'), $module['observed_dependencies'], $this->translator->text('cli.inspect.none_verified'));
        foreach ($module['references'] as $reference) {
            $style->text($this->translator->text('cli.inspect.reference_at', ['class' => $reference['class'], 'file' => $reference['file'], 'line' => $reference['line']]), '    ');
        }
        $this->section($style, $this->translator->text('cli.inspect.incoming_declared'), $view['incoming'], $this->translator->text('cli.inspect.none'));
        $this->section($style, $this->translator->text('cli.inspect.incoming_observed'), array_values(array_unique(array_column($view['incoming_references'], 'source'))), $this->translator->text('cli.inspect.none_verified'));
        foreach ($view['incoming_references'] as $incoming) {
            $reference = $incoming['reference'];
            $style->text($incoming['source'].' → '.$this->translator->text('cli.inspect.reference_at', ['class' => $reference['class'], 'file' => $reference['file'], 'line' => $reference['line']]), '    ');
        }
        $this->originals($style, array_values(array_merge($view['findings'], $view['blockers'])));
    }

    /** @param list<array<string, mixed>> $findings */
    private function originals(ConsoleStyle $style, array $findings): void
    {
        $style->rule();
        $style->text($this->translator->text('cli.inspect.originals'), style: 'options=bold');
        if ([] === $findings) {
            $style->text($this->translator->text('cli.inspect.no_originals'));
        }
        foreach ($findings as $finding) {
            $style->text('['.$finding['rule'].'] '.$finding['severity'].': '.$finding['message']);
            $style->text($this->translator->text('cli.inspect.context', ['context' => (string) json_encode($finding['context'], \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR)]));
        }
    }

    /** @param list<string> $items */
    private function section(ConsoleStyle $style, string $title, array $items, string $empty): void
    {
        $style->rule();
        $style->text($title, style: 'options=bold');
        $items = array_values(array_unique($items));
        sort($items, \SORT_STRING);
        if ([] === $items) {
            $style->text($empty);
        }
        foreach ($items as $item) {
            $style->text($item, '    ');
        }
    }

    /** @param list<array<string, mixed>> $findings */
    private function findings(ConsoleStyle $style, array $findings, ModuleInspectionPresentation $presentation): void
    {
        foreach ($findings as $finding) {
            $message = $presentation->problem($finding);
            $style->error('['.$finding['rule'].'] '.$message);
            if (null !== ($location = $presentation->location($finding))) {
                $style->text($location, '    ');
            }
            if (null !== ($caution = $presentation->caution($finding))) {
                $style->note($caution);
            }
        }
    }

    private static function boolean(?bool $value): string
    {
        return null === $value ? 'unknown' : ($value ? 'true' : 'false');
    }
}
