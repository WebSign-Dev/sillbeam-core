<?php

declare(strict_types=1);

namespace Sillbeam\Core\Console;

use Sillbeam\Core\Console\Style\ConsoleStyle;
use Sillbeam\Core\Console\Style\LocalizedCommand;
use Sillbeam\Core\I18n\CoreError;
use Sillbeam\Core\I18n\CoreTranslator;
use Sillbeam\Core\I18n\LocaleResolver;
use Sillbeam\Core\I18n\UserLocaleStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** @internal */
#[AsCommand(name: 'sillbeam:locale', description: 'Show or change the personal Sillbeam locale.')]
final class LocaleCommand extends LocalizedCommand
{
    public function __construct(private readonly UserLocaleStore $store, private readonly LocaleResolver $resolver, CoreTranslator $translator = new CoreTranslator())
    {
        parent::__construct($translator);
    }

    protected function configure(): void
    {
        $this->addOption('set', null, InputOption::VALUE_REQUIRED, $this->translator->text('cli.locale.set'));
        $this->addOption('reset', null, InputOption::VALUE_NONE, $this->translator->text('cli.locale.reset'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new ConsoleStyle($output);
        $set = $input->getOption('set');
        $reset = $input->getOption('reset');
        $explicit = $input->getOption('locale');
        try {
            if (null !== $set && $reset) {
                throw CoreError::create(\InvalidArgumentException::class, 'cli.locale.conflict');
            }
            if (null !== $set) {
                $this->store->set($set);
            } elseif ($reset) {
                $this->store->reset();
            }
            $effective = $this->resolver->resolve($explicit);
            $translator = new CoreTranslator($effective);
            $style->text($translator->text('cli.locale.current', ['effective' => $effective, 'personal' => $this->store->read() ?? $translator->text('cli.locale.unset')]));

            return self::SUCCESS;
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            $style->error(CoreError::render($error, $this->translator));

            return self::INVALID;
        }
    }
}
