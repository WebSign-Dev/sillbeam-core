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
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates a Project descriptor through the shared guarded descriptor creator.
 *
 * @internal
 */
#[AsCommand(name: 'sillbeam:make:project', description: 'Create a minimal Sillbeam project descriptor.')]
final class MakeProjectCommand extends Command
{
    public function __construct(private readonly DescriptorCreator $creator, private readonly CoreTranslator $translator = new CoreTranslator())
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('Name', InputArgument::REQUIRED, $this->translator->text('cli.make.project_name'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new ConsoleStyle($output);
        try {
            $name = $input->getArgument('Name');
            $file = $this->creator->create($name, project: true);
            $io->success($this->translator->text('cli.make.created', ['path' => $file]));
            $io->note($this->translator->text('cli.make.hosts'));

            return Command::SUCCESS;
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            $io->error(CoreError::render($error, $this->translator));

            return Command::FAILURE;
        }
    }
}
