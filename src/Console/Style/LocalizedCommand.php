<?php

declare(strict_types=1);

namespace Sillbeam\Core\Console\Style;

use Sillbeam\Core\I18n\CoreTranslator;
use Sillbeam\Core\I18n\LocaleResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** @internal Shared locale binding for human-facing Core commands. */
abstract class LocalizedCommand extends Command
{
    private ?LocaleResolver $localeResolver = null;
    private CoreTranslator $initialTranslator;

    public function __construct(protected CoreTranslator $translator = new CoreTranslator())
    {
        $this->initialTranslator = $translator;
        parent::__construct();
        $this->addOption('locale', null, InputOption::VALUE_REQUIRED, $translator->text('cli.locale.option'));
        $this->setDescription($translator->text('cli.command.'.(string) $this->getName()));
    }

    public function useLocaleResolver(LocaleResolver $resolver): void
    {
        $this->localeResolver = $resolver;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->translator = $this->initialTranslator;
        $explicit = $input->getOption('locale');
        if (null !== $explicit) {
            $this->translator = new CoreTranslator(LocaleResolver::requireSupported($explicit));
        } elseif (null !== $this->localeResolver && (!$input->hasOption('format') || 'json' !== $input->getOption('format'))) {
            $this->translator = new CoreTranslator($this->localeResolver->resolve());
        }
    }
}
