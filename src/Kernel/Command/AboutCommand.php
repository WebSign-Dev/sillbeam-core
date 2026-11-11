<?php

declare(strict_types=1);

namespace Sillbeam\Core\Kernel\Command;

use Sillbeam\Core\Console\Style\ConsoleStyle;
use Sillbeam\Core\Console\Style\LocalizedCommand;
use Sillbeam\Core\I18n\CoreTranslator;
use Sillbeam\Core\Kernel\Runtime\KernelInformationInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders scalar kernel information through the shared console style.
 *
 * @internal
 */
#[AsCommand(name: 'sillbeam:about', description: 'Display the important Sillbeam runtime and module information.')]
final class AboutCommand extends LocalizedCommand
{
    /** @param KernelInformationInterface|\Closure(): KernelInformationInterface $information */
    public function __construct(private readonly KernelInformationInterface|\Closure $information, CoreTranslator $translator = new CoreTranslator())
    {
        parent::__construct($translator);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new ConsoleStyle($output);
        $io->heading($this->translator->text('cli.about.title'));
        $information = $this->information instanceof \Closure ? ($this->information)() : $this->information;
        foreach ($information->all() as $key => $value) {
            $label = match ($key) {
                'application', 'environment', 'debug', 'php', 'symfony', 'sillbeam', 'modules', 'modules_enabled' => $this->translator->text('cli.about.'.$key),
                default => ucwords(str_replace('_', ' ', $key)),
            };
            if (('debug' === $key && \in_array($value, ['yes', 'no'], true)) || ('sillbeam' === $key && \in_array($value, ['source', 'unknown'], true))) {
                $value = $this->translator->text('cli.label.'.$value);
            }
            $io->text($label.': '.$value);
        }

        return self::SUCCESS;
    }
}
