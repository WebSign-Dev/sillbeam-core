<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture\Console;

use Sillbeam\Core\Architecture\DescriptorCreator;
use Sillbeam\Core\Console\Style\ConsoleStyle;
use Sillbeam\Core\I18n\CoreError;
use Sillbeam\Core\I18n\CoreTranslator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates a module descriptor through the shared guarded descriptor creator.
 *
 * @internal
 */
#[AsCommand(name: 'sillbeam:make:module', description: 'Create a minimal Sillbeam module descriptor.')]
final class MakeModuleCommand extends Command
{
    public function __construct(private readonly DescriptorCreator $creator, private readonly CoreTranslator $translator = new CoreTranslator())
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('Name', InputArgument::REQUIRED, $this->translator->text('cli.make.module_name'));
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, $this->translator->text('cli.make.project'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new ConsoleStyle($output);
        try {
            $name = $input->getArgument('Name');
            $file = $this->creator->create($name, owner: $input->getOption('project'));
            $io->success($this->translator->text('cli.make.created', ['path' => $file]));

            return Command::SUCCESS;
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            $io->error(CoreError::render($error, $this->translator));

            return Command::FAILURE;
        }
    }
}
