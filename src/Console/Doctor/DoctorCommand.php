<?php

declare(strict_types=1);

namespace Sillbeam\Core\Console\Doctor;

use Sillbeam\Core\Console\Style\ConsoleStyle;
use Sillbeam\Core\I18n\CoreTranslator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Runs installation diagnostics and writes a console report without changing application configuration.
 *
 * @internal
 */
#[AsCommand(name: 'sillbeam:doctor', description: 'Check static Symfony installation evidence without changing files.')]
final class DoctorCommand extends Command
{
    public function __construct(private readonly string $projectDir, private readonly string $environment, private readonly ?KernelInterface $kernel = null, private readonly CoreTranslator $translator = new CoreTranslator())
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, $this->translator->text('doctor.cli.format'), 'text');
        $this->setHelp($this->translator->text('doctor.cli.help'));
    }

    /** Handles binding failures before execute(), including the ordinary Symfony entry point. */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        $json = self::isJsonRequested($input);
        $level = ob_get_level();
        if ($json) {
            ob_start();
            set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
                if (!(error_reporting() & $severity)) {
                    return false;
                }
                throw new \ErrorException($message, 0, $severity, $file, $line);
            });
        }
        try {
            return parent::run($input, $output);
        } catch (\Symfony\Component\Console\Exception\ExceptionInterface $error) {
            return self::failure($error, 'doctor.usage', $json, $output);
        } catch (\Throwable $error) {
            return self::failure($error, 'doctor.execution', $json, $output);
        } finally {
            if ($json) {
                restore_error_handler();
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }
            }
        }
    }

    /**
     * Resolves the final exact --format option even when binding stops at an invalid option.
     *
     * Symfony's getParameterOption() returns the first occurrence, while binding
     * retains the last. Honor both value spellings and the end-of-options marker
     * so error rendering and buffering agree with the effective command format.
     */
    public static function isJsonRequested(InputInterface $input): bool
    {
        if (!$input instanceof ArgvInput) {
            return 'json' === $input->getParameterOption('--format', 'text', true);
        }
        $format = 'text';
        $tokens = $input->getRawTokens();
        foreach ($tokens as $index => $token) {
            if ('--' === $token) {
                break;
            }
            if ('--format' !== $token && !str_starts_with($token, '--format=')) {
                continue;
            }
            $format = '--format' === $token ? null : substr($token, \strlen('--format='));
            if ('--format' === $token && isset($tokens[$index + 1]) && !str_starts_with($tokens[$index + 1], '-')) {
                $format = $tokens[$index + 1];
            }
        }

        return 'json' === $format;
    }

    /** @param 'doctor.usage'|'doctor.execution' $code */
    public static function failure(\Throwable $error, string $code, bool $json, OutputInterface $output): int
    {
        if ($json) {
            if (!$output->isQuiet()) {
                $output->writeln(DoctorReport::failure($error, $code)->toJson(), OutputInterface::OUTPUT_RAW);
            }
        } else {
            (new ConsoleStyle($output))->error($error->getMessage());
        }

        return 2;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        if (!\in_array($format, ['text', 'json'], true)) {
            return self::failure(new \InvalidArgumentException($this->translator->text('doctor.cli.invalid_format')), 'doctor.usage', false, $output);
        }
        $style = new ConsoleStyle($output);
        if ('text' === $format) {
            $style->heading($this->translator->text('doctor.cli.title'));
        }
        $checks = (new InstallationInspector())->inspect($this->projectDir, $this->environment, null === $this->kernel ? null : ((new \ReflectionClass($this->kernel))->getFileName() ?: ''), 'json' === $format ? new CoreTranslator() : $this->translator);
        $report = DoctorReport::fromChecks($checks);
        if ('json' === $format) {
            if (!$output->isQuiet()) {
                $output->writeln($report->toJson(), OutputInterface::OUTPUT_RAW);
            }

            return $report->exitCode();
        }
        $counts = $report->data['summary'];
        $style->text($this->translator->text('doctor.cli.summary', ['verified' => $counts['verified'] ?? 0, 'action' => $counts['action required'] ?? 0, 'unknown' => $counts['not verified'] ?? 0]));
        $style->text($this->translator->text('doctor.cli.scope'));
        foreach ($checks as $check) {
            $message = $check['label'].': '.$this->translator->text('doctor.status.'.str_replace(' ', '_', $check['status']));
            match ($check['status']) {
                'verified' => $style->success($message),
                'action required' => $style->error($message),
                default => $style->note($message),
            };
            if ('' !== $check['detail']) {
                $style->text($check['detail'], '     ');
            }
        }
        $style->text($counts['not verified'] > 0 || $counts['action required'] > 0
            ? $this->translator->text('doctor.cli.next_review')
            : $this->translator->text('doctor.cli.next_architecture'));

        return $report->exitCode();
    }
}
