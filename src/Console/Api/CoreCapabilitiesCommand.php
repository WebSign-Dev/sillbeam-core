<?php

declare(strict_types=1);

namespace Sillbeam\Core\Console\Api;

use Sillbeam\Core\Api\CoreCapabilitiesProvider;
use Sillbeam\Core\Api\CoreCapabilitiesProviderInterface;
use Sillbeam\Core\I18n\CoreError;
use Sillbeam\Core\I18n\CoreTranslator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** @internal */
#[AsCommand(name: 'sillbeam:api:capabilities', description: 'Report the supported Core Data profiles and operations.')]
final class CoreCapabilitiesCommand extends Command
{
    /** @param (\Closure(?string): CoreTranslator)|null $errorTranslator */
    public function __construct(
        private readonly CoreCapabilitiesProviderInterface $provider = new CoreCapabilitiesProvider(),
        private readonly CoreTranslator $translator = new CoreTranslator(),
        private readonly ?\Closure $errorTranslator = null,
    ) {
        parent::__construct();
        $this->setDescription($translator->text('cli.command.'.(string) $this->getName()));
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, $this->translator->text('cli.api.capabilities_format'), 'json');
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (\Throwable $error) {
            return CoreDataCommand::failure($error, $output, $this->errorTranslator?->__invoke(null) ?? $this->translator);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ('json' !== $input->getOption('format')) {
            throw CoreError::create(\InvalidArgumentException::class, 'api.capabilities_format');
        }
        $json = json_encode($this->provider->capabilities(), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        $output->writeln($json, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);

        return 0;
    }
}
