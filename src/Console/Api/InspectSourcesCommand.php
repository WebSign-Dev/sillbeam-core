<?php

declare(strict_types=1);

namespace Sillbeam\Core\Console\Api;

use Sillbeam\Core\Api\CoreDataProvider;
use Sillbeam\Core\I18n\CoreError;
use Sillbeam\Core\I18n\CoreTranslator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** @internal */
#[AsCommand(name: 'sillbeam:inspect:sources', description: 'Export the source inventory as JSON.')]
final class InspectSourcesCommand extends Command
{
    public function __construct(private readonly CoreDataProvider $provider)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format (json).', 'json');
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (\Throwable $error) {
            return CoreDataCommand::failure($error, $output);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ('json' !== $input->getOption('format')) {
            throw CoreError::create(\InvalidArgumentException::class, 'api.inventory.format');
        }
        $level = ob_get_level();
        ob_start();
        try {
            $inventory = $this->provider->inspectSources();
            $json = json_encode($inventory['data'], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
        $output->writeln($json, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);
        if ($output instanceof ConsoleOutputInterface) {
            $translator = new CoreTranslator();
            foreach ($inventory['errors'] as $error) {
                $output->getErrorOutput()->writeln(
                    $translator->text('api.'.$error['code']).(null === $error['path'] ? '' : ' '.$error['path']),
                    OutputInterface::OUTPUT_RAW,
                );
            }
        }

        return match ($inventory['status']) {
            'complete' => 0,
            'partial' => 3,
            default => 2,
        };
    }
}
