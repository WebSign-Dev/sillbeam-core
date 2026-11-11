<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture\Console;

use Composer\InstalledVersions;
use Sillbeam\Core\Architecture\ArchitectureReport;
use Sillbeam\Core\Architecture\FilesystemPath;
use Sillbeam\Core\Console\Style\ConsoleStyle;
use Sillbeam\Core\Console\Style\LocalizedCommand;
use Sillbeam\Core\I18n\CoreError;
use Sillbeam\Core\I18n\CoreTranslator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes the architecture catalog as Markdown using the shared report provider.
 *
 * @internal
 */
#[AsCommand(name: 'sillbeam:report', description: 'Write a standalone Markdown architecture report.')]
final class ReportCommand extends LocalizedCommand
{
    /** @param ArchitectureReport|\Closure(CoreTranslator): ArchitectureReport $report */
    public function __construct(private readonly ArchitectureReport|\Closure $report, private readonly string $projectDir, CoreTranslator $translator = new CoreTranslator())
    {
        parent::__construct($translator);
    }

    protected function configure(): void
    {
        $this->addOption('couplings', null, InputOption::VALUE_NONE, $this->translator->text('cli.report.couplings'));
        $this->setHelp($this->translator->text('cli.report.help'));
        $this->addOption('output', null, InputOption::VALUE_REQUIRED, $this->translator->text('cli.report.output'), 'architecture-report.md');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new ConsoleStyle($output);
        $report = ArchitectureReport::collectLocalized($this->report, $this->translator);
        $temporary = null;
        try {
            $path = $input->getOption('output');
            if (!\is_string($path) || '' === trim($path) || str_contains($path, "\0") || str_contains($path, '://')) {
                throw new \RuntimeException($this->translator->text('cli.report.invalid_path'));
            }
            $path = FilesystemPath::normalize($path);
            if (!str_starts_with($path, '/') && !preg_match('/^[a-zA-Z]:\//', $path)) {
                $path = $this->projectDir.'/'.$path;
            }
            $directory = \dirname($path);
            if (!is_dir($directory)) {
                throw new \RuntimeException($this->translator->text('cli.report.missing_directory', ['path' => $directory]));
            }
            $version = InstalledVersions::isInstalled('websign-dev/sillbeam-core') ? (InstalledVersions::getPrettyVersion('websign-dev/sillbeam-core') ?? 'unknown') : 'source';
            $markdown = (new ReportRenderer($report, $this->translator))->markdown($version, new \DateTimeImmutable(), (bool) $input->getOption('couplings'));
            $temporary = @tempnam($directory, '.sillbeam-report-');
            if (false === $temporary || realpath(\dirname($temporary)) !== realpath($directory)) {
                throw new \RuntimeException($this->translator->text('cli.report.temporary', ['path' => $directory]));
            }
            if (\strlen($markdown) !== @file_put_contents($temporary, $markdown)) {
                throw new \RuntimeException($this->translator->text('cli.report.write', ['path' => $directory]));
            }
            if (!@rename($temporary, $path)) {
                throw new \RuntimeException($this->translator->text('cli.report.replace', ['path' => $path]));
            }
            $io->success($this->translator->text('cli.report.written', ['path' => $path, 'status' => $this->translator->text('cli.label.'.$report->data['status'])]));

            return $report->exitCode();
        } catch (\Throwable $error) {
            $io->error($this->translator->text('cli.report.failure', ['error' => CoreError::render($error, $this->translator)]));

            return 2;
        } finally {
            if (\is_string($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
