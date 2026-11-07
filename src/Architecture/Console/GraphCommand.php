<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture\Console;

use Sillbeam\Core\Architecture\ArchitectureReport;
use Sillbeam\Core\I18n\CoreError;
use Sillbeam\Core\I18n\CoreTranslator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** @internal SVG or raw Mermaid export of existing report evidence, without container boot. */
#[AsCommand(name: 'sillbeam:graph', description: 'Render module architecture as SVG or export raw Mermaid source.')]
final class GraphCommand extends Command
{
    /** @param ArchitectureReport|\Closure(): ArchitectureReport $report */
    public function __construct(private readonly ArchitectureReport|\Closure $report, private readonly CoreTranslator $translator = new CoreTranslator())
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, $this->translator->text('cli.graph.format'), 'svg');
        $this->addOption('output', null, InputOption::VALUE_REQUIRED, $this->translator->text('cli.graph.output'));
        $this->addOption('module', null, InputOption::VALUE_REQUIRED, $this->translator->text('cli.graph.module'));
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, $this->translator->text('cli.graph.project'));
        $this->addOption('force', null, InputOption::VALUE_NONE, $this->translator->text('cli.graph.force'));
        $this->setHelp($this->translator->text('cli.graph.help'));
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (\Symfony\Component\Console\Exception\ExceptionInterface $error) {
            return self::usageError($error, $output, $this->translator);
        }
    }

    /** Writes plain usage errors to STDERR when the output exposes it; never adds Mermaid source. */
    public static function usageError(\Throwable $error, OutputInterface $output, CoreTranslator $translator = new CoreTranslator()): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $errors->writeln('sillbeam:graph: '.preg_replace('/[\x00-\x1F\x7F]/', ' ', CoreError::render($error, $translator)), OutputInterface::OUTPUT_RAW);

        return 3;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        if (!\in_array($format, ['svg', 'mermaid'], true)) {
            return self::usageError(CoreError::create(\InvalidArgumentException::class, 'cli.graph.invalid_format'), $output, $this->translator);
        }
        foreach (['module', 'project', 'output'] as $option) {
            $value = $input->getOption($option);
            if (null !== $value && (!\is_string($value) || '' === trim($value) || preg_match('/[\x00-\x1f\x7f]/', $value))) {
                return self::usageError(CoreError::create(\InvalidArgumentException::class, 'cli.graph.invalid_option', ['option' => $option]), $output, $this->translator);
            }
        }
        $force = (bool) $input->getOption('force');
        if ('mermaid' === $format && (null !== $input->getOption('output') || $force)) {
            return self::usageError(CoreError::create(\InvalidArgumentException::class, 'cli.graph.mermaid_options'), $output, $this->translator);
        }
        $report = ArchitectureReport::collect($this->report);
        try {
            $view = GraphView::fromReport($report, $input->getOption('module'), $input->getOption('project'), $this->translator);
        } catch (\InvalidArgumentException $error) {
            return self::usageError($error, $output, $this->translator);
        }
        if ('mermaid' === $format) {
            $output->write((new MermaidGraph($this->translator))->render($view), false, OutputInterface::OUTPUT_RAW);
        } else {
            try {
                $writer = new SvgOutput();
                $destination = $writer->destination($input->getOption('output') ?? 'architecture.svg', $force);
                $svg = (new SvgGraph($this->translator))->render($view);
                $writer->write($destination, $svg, $force);
            } catch (\RuntimeException $error) {
                self::usageError($error, $output, $this->translator);

                return 4;
            }
            $output->writeln($this->translator->text('cli.graph.written', ['path' => $destination]), OutputInterface::OUTPUT_RAW);
        }

        return $report->exitCode();
    }
}
