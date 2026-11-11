<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture\Console;

use Sillbeam\Core\Architecture\ArchitectureReport;
use Sillbeam\Core\Console\Style\ConsoleStyle;
use Sillbeam\Core\Console\Style\LocalizedCommand;
use Sillbeam\Core\I18n\CoreError;
use Sillbeam\Core\I18n\CoreTranslator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders structured architecture findings and maps report status to CLI exit codes.
 *
 * @internal
 */
#[AsCommand(name: 'sillbeam:architecture', description: 'Explain architecture findings and evidence-based next steps.')]
final class ArchitectureCommand extends LocalizedCommand
{
    /** @param ArchitectureReport|\Closure(CoreTranslator): ArchitectureReport $report */
    public function __construct(private readonly ArchitectureReport|\Closure $report, CoreTranslator $translator = new CoreTranslator())
    {
        parent::__construct($translator);
    }

    protected function configure(): void
    {
        $translator = $this->translator;
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
        } catch (\Symfony\Component\Console\Exception\ExceptionInterface|\InvalidArgumentException|\RuntimeException $error) {
            // Binding failures occur before execute(), also in a regular Symfony Application.
            return self::usageError($error, 'json' === $input->getParameterOption('--format'), $input, $output, $this->translator);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        try {
            self::validate($input);
        } catch (\InvalidArgumentException $error) {
            return self::usageError($error, 'json' === $format, $input, $output, $this->translator);
        }
        $report = ArchitectureReport::collectLocalized($this->report, $this->translator);
        try {
            return self::render($report, $format, $input, $output, $this->translator);
        } catch (\InvalidArgumentException $error) {
            return self::usageError($error, 'json' === $format, $input, $output, $this->translator);
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

    public static function usageError(\Throwable $error, bool $json, InputInterface $input, OutputInterface $output, CoreTranslator $translator = new CoreTranslator()): int
    {
        if ($json) {
            return self::render(ArchitectureReport::unavailable($error), 'json', $input, $output);
        }
        (new ConsoleStyle($output))->error(CoreError::render($error, $translator));

        return 2;
    }

    public static function render(ArchitectureReport $report, string $format, InputInterface $input, OutputInterface $output, CoreTranslator $translator = new CoreTranslator()): int
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
        (new ArchitectureRenderer($output, verbose: $output->isVerbose(), all: $all, issueNumber: null === $issue ? null : (int) $issue, showCouplings: $couplings, translator: $translator))->render([] === $report->presentation ? ArchitecturePresentation::fromData($report->data, $translator) : $report->presentation);

        return $report->exitCode();
    }
}
