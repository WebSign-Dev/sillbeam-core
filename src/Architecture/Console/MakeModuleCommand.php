<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture\Console;

use Sillbeam\Core\Architecture\ArchitectureValidationException;
use Sillbeam\Core\Architecture\DescriptorCreator;
use Sillbeam\Core\Console\Style\ConsoleStyle;
use Sillbeam\Core\Console\Style\LocalizedCommand;
use Sillbeam\Core\I18n\CoreError;
use Sillbeam\Core\I18n\CoreTranslator;
use Symfony\Component\Console\Attribute\AsCommand;
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
final class MakeModuleCommand extends LocalizedCommand
{
    /** @param DescriptorCreator|\Closure(): DescriptorCreator $creator */
    public function __construct(private readonly DescriptorCreator|\Closure $creator, CoreTranslator $translator = new CoreTranslator())
    {
        parent::__construct($translator);
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
            $creator = $this->creator instanceof \Closure ? ($this->creator)() : $this->creator;
            $file = $creator->create($name, owner: $input->getOption('project'));
            $io->success($this->translator->text('cli.make.created', ['path' => $file]));

            return self::SUCCESS;
        } catch (\InvalidArgumentException|\RuntimeException|ArchitectureValidationException $error) {
            $io->error($error instanceof ArchitectureValidationException ? $error->localizedMessage($this->translator) : CoreError::render($error, $this->translator));

            return self::FAILURE;
        }
    }
}
