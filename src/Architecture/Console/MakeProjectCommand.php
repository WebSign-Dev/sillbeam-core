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
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates a Project descriptor through the shared guarded descriptor creator.
 *
 * @internal
 */
#[AsCommand(name: 'sillbeam:make:project', description: 'Create a minimal Sillbeam project descriptor.')]
final class MakeProjectCommand extends LocalizedCommand
{
    /** @param DescriptorCreator|\Closure(): DescriptorCreator $creator */
    public function __construct(private readonly DescriptorCreator|\Closure $creator, CoreTranslator $translator = new CoreTranslator())
    {
        parent::__construct($translator);
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
            $creator = $this->creator instanceof \Closure ? ($this->creator)() : $this->creator;
            $file = $creator->create($name, project: true);
            $io->success($this->translator->text('cli.make.created', ['path' => $file]));
            $io->note($this->translator->text('cli.make.hosts'));

            return self::SUCCESS;
        } catch (\InvalidArgumentException|\RuntimeException|ArchitectureValidationException $error) {
            $io->error($error instanceof ArchitectureValidationException ? $error->localizedMessage($this->translator) : CoreError::render($error, $this->translator));

            return self::FAILURE;
        }
    }
}
