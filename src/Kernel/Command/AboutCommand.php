<?php

declare(strict_types=1);

namespace Sillbeam\Core\Kernel\Command;

use Sillbeam\Core\Console\Style\ConsoleStyle;
use Sillbeam\Core\I18n\CoreTranslator;
use Sillbeam\Core\Kernel\Runtime\KernelInformationInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders scalar kernel information through the shared console style.
 *
 * @internal
 */
#[AsCommand(name: 'sillbeam:about', description: 'Display the important Sillbeam runtime and module information.')]
final class AboutCommand extends Command
{
    public function __construct(private readonly KernelInformationInterface $information, private readonly CoreTranslator $translator = new CoreTranslator())
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new ConsoleStyle($output);
        $io->heading($this->translator->text('cli.about.title'));
        foreach ($this->information->all() as $key => $value) {
            $label = match ($key) {
                'application', 'environment', 'debug', 'php', 'symfony', 'sillbeam', 'modules', 'modules_enabled' => $this->translator->text('cli.about.'.$key),
                default => ucwords(str_replace('_', ' ', $key)),
            };
            $io->text($label.': '.$value);
        }

        return self::SUCCESS;
    }
}
