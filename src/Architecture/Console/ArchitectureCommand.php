<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture\Console;

use Sillbeam\Core\Architecture\ArchitectureReport;
use Sillbeam\Core\Console\Style\ConsoleStyle;
use Sillbeam\Core\I18n\CoreError;
use Sillbeam\Core\I18n\CoreTranslator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders structured architecture findings and maps report status to CLI exit codes.
 *
 * @internal
 */
#[AsCommand(name: 'sillbeam:architecture', description: 'Explain architecture findings and evidence-based next steps.')]
final class ArchitectureCommand extends Command
{
    /** @param ArchitectureReport|\Closure(): ArchitectureReport $report */
    public function __construct(private readonly ArchitectureReport|\Closure $report)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $translator = new CoreTranslator();
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, $translator->text('architecture.cli.format'), 'text');
        $this->addOption('all', null, InputOption::VALUE_NONE, $translator->text('architecture.cli.all'));
        $this->addOption('issue', null, InputOption::VALUE_REQUIRED, $translator->text('architecture.cli.issue'));
        $this->addOption('couplings', null, InputOption::VALUE_NONE, $translator->text('architecture.cli.couplings'));
        $this->setHelp($translator->text('architecture.cli.help'));
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (\Symfony\Component\Console\Exception\ExceptionInterface $error) {
            // Binding failures occur before execute(), also in a regular Symfony Application.
            return self::usageError($error, 'json' === $input->getParameterOption('--format'), $input, $output);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        try {
            self::validate($input);
        } catch (\InvalidArgumentException $error) {
            return self::usageError($error, 'json' === $format, $input, $output);
        }
        $report = ArchitectureReport::collect($this->report);
        try {
            return self::render($report, $format, $input, $output);
        } catch (\InvalidArgumentException $error) {
            return self::usageError($error, 'json' === $format, $input, $output);
        }
    }

    private static function validate(InputInterface $input): void
    {
        $format = $input->getOption('format');
        if (!\in_array($format, ['text', 'json'], true)) {
            throw CoreError::create(\InvalidArgumentException::class, 'architecture.cli.invalid_format');
        }
        if ($input->hasParameterOption('--ansi') && $input->hasParameterOption('--no-ansi')) {
            throw CoreError::create(\InvalidArgumentException::class, 'architecture.cli.ansi_options');
        }
        $issue = $input->getOption('issue');
        if (null !== $issue && false === filter_var($issue, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
            throw CoreError::create(\InvalidArgumentException::class, 'architecture.cli.positive_issue');
        }
        if (null !== $issue && $input->getOption('all')) {
            throw CoreError::create(\InvalidArgumentException::class, 'architecture.cli.issue_options');
        }
        if ('json' === $format && (null !== $issue || $input->getOption('all') || $input->getOption('couplings'))) {
            throw CoreError::create(\InvalidArgumentException::class, 'architecture.cli.json_options');
        }
    }

    public static function usageError(\Throwable $error, bool $json, InputInterface $input, OutputInterface $output): int
    {
        if ($json) {
            return self::render(ArchitectureReport::unavailable($error), 'json', $input, $output);
        }
        (new ConsoleStyle($output))->error($error->getMessage());

        return 2;
    }

    public static function render(ArchitectureReport $report, string $format, InputInterface $input, OutputInterface $output): int
    {
        if ('json' === $format) {
            if (!$output->isQuiet()) {
                $output->writeln($report->toJson(), OutputInterface::OUTPUT_RAW);
            }

            return $report->exitCode();
        }
        $all = $input->hasOption('all') && $input->getOption('all');
        $issue = $input->hasOption('issue') ? $input->getOption('issue') : null;
        $couplings = $input->hasOption('couplings') && $input->getOption('couplings');
        (new ArchitectureRenderer($output, verbose: $output->isVerbose(), all: $all, issueNumber: null === $issue ? null : (int) $issue, showCouplings: $couplings))->render([] === $report->presentation ? ArchitecturePresentation::fromData($report->data) : $report->presentation);

        return $report->exitCode();
    }
}
