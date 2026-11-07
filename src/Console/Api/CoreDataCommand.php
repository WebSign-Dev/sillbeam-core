<?php

declare(strict_types=1);

namespace Sillbeam\Core\Console\Api;

use Sillbeam\Core\Api\CoreDataJsonSerializer;
use Sillbeam\Core\Api\CoreDataProvider;
use Sillbeam\Core\Api\CoreDataProviderInterface;
use Sillbeam\Core\Api\CoreDataRequest;
use Sillbeam\Core\Console\KernelArchitectureReporter;
use Sillbeam\Core\I18n\CoreError;
use Sillbeam\Core\I18n\CoreTranslator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/** @internal */
#[AsCommand(name: 'sillbeam:api', description: 'Export the Sillbeam architecture snapshot as JSON.')]
final class CoreDataCommand extends Command
{
    public function __construct(private readonly CoreDataProviderInterface $provider)
    {
        parent::__construct();
    }

    public static function forKernel(KernelInterface $kernel): self
    {
        return new self(new CoreDataProvider(static fn () => (new KernelArchitectureReporter())->configuration($kernel)));
    }

    protected function configure(): void
    {
        $this->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Core Data profile.', 'live');
        $this->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Locale for Live output.');
        $this->addOption('schema-version', null, InputOption::VALUE_REQUIRED, 'Core Data schema version.', '1');
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (\Throwable $error) {
            return self::failure($error, $output, self::translator($input));
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $version = filter_var($input->getOption('schema-version'), \FILTER_VALIDATE_INT);
        if (false === $version) {
            throw CoreError::create(\InvalidArgumentException::class, 'api.invalid_schema_option');
        }
        $request = CoreDataRequest::fromProfile($input->getOption('profile'), $input->getOption('locale'), $version);
        $level = ob_get_level();
        ob_start();
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            if ($severity & (\E_DEPRECATED | \E_USER_DEPRECATED | \E_NOTICE | \E_USER_NOTICE)) {
                return true;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $document = $this->provider->collect($request);
            $json = (new CoreDataJsonSerializer())->serialize($document);
        } finally {
            restore_error_handler();
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        $exit = match (true) {
            'unavailable' === $document->analysis->fields['status'] => 2,
            'partial' === $document->export->fields['status'] => 3,
            'violations' === $document->analysis->fields['status'] => 1,
            default => 0,
        };
        $output->writeln($json, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);

        return $exit;
    }

    public static function failure(\Throwable $error, OutputInterface $output, CoreTranslator $translator = new CoreTranslator()): int
    {
        $message = $error instanceof \InvalidArgumentException || $error instanceof \Symfony\Component\Console\Exception\ExceptionInterface
            ? CoreError::render($error, $translator)
            : $translator->text('api.export_failed');
        if ($output instanceof ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln($message, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);
        } else {
            fwrite(\STDERR, $message."\n");
        }

        return 4;
    }

    private static function translator(InputInterface $input): CoreTranslator
    {
        $locale = $input->getParameterOption('--locale', CoreTranslator::DEFAULT_LOCALE, true);

        return new CoreTranslator(\is_string($locale) && \in_array($locale, CoreTranslator::locales(), true) ? $locale : CoreTranslator::DEFAULT_LOCALE);
    }
}
