<?php

declare(strict_types=1);

namespace Sillbeam\Core\Console;

use Sillbeam\Core\Architecture\ArchitectureReport;
use Sillbeam\Core\Architecture\Console\ArchitectureCommand;
use Sillbeam\Core\Architecture\Console\GraphCommand;
use Sillbeam\Core\Architecture\Console\InspectModuleCommand;
use Sillbeam\Core\Architecture\Console\MakeModuleCommand;
use Sillbeam\Core\Architecture\Console\MakeProjectCommand;
use Sillbeam\Core\Architecture\Console\ReportCommand;
use Sillbeam\Core\Architecture\DescriptorCreator;
use Sillbeam\Core\Console\Api\CoreCapabilitiesCommand;
use Sillbeam\Core\Console\Api\CoreDataCommand;
use Sillbeam\Core\Console\Api\InspectSourcesCommand;
use Sillbeam\Core\Console\Doctor\DoctorCommand;
use Sillbeam\Core\Console\Doctor\InstallationInspector;
use Sillbeam\Core\Console\Style\LocalizedCommand;
use Sillbeam\Core\I18n\CoreTranslator;
use Sillbeam\Core\I18n\LocaleResolver;
use Sillbeam\Core\I18n\UserLocaleStore;
use Sillbeam\Core\Kernel\Command\AboutCommand;
use Sillbeam\Core\Kernel\Runtime\KernelInformation;
use Sillbeam\Core\Module\ModuleRegistryInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Symfony console adapter that provides architecture, module inspection, graph, report and doctor commands before container boot.
 *
 * Other commands retain Symfony dispatch. Use this class in the existing console bootstrap
 * to obtain diagnostics even when container compilation fails.
 *
 * @api
 */
final class Application extends SymfonyApplication
{
    private const array HUMAN_COMMANDS = [
        'sillbeam:architecture', 'sillbeam:doctor', 'sillbeam:report', 'sillbeam:inspect:module',
        'sillbeam:graph', 'sillbeam:make:module', 'sillbeam:make:project', 'sillbeam:about', 'sillbeam:locale',
    ];
    private const array API_COMMANDS = ['sillbeam:api', 'sillbeam:api:capabilities', 'sillbeam:inspect:sources'];

    /**
     * Dispatches diagnostic commands without booting the container and delegates other commands to Symfony.
     *
     * Writes command/help/error output; analysis uses status codes 0, 1 and 2.
     * Graph invocation and renderer/output failures use 3 and 4 respectively.
     */
    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        $command = $this->coreCommand('sillbeam:architecture', new CoreTranslator());
        $definition = clone $this->getDefinition();
        foreach ($command->getDefinition()->getOptions() as $option) {
            $definition->addOption($option);
        }
        // Read option metadata without resolving a kernel-derived output directory.
        $definition->addOption($this->coreCommand('sillbeam:report', new CoreTranslator(), '')->getDefinition()->getOption('output'));
        $definition->addOption($this->coreCommand('sillbeam:inspect:module', new CoreTranslator())->getDefinition()->getOption('project'));
        foreach ($this->coreCommand('sillbeam:graph', new CoreTranslator())->getDefinition()->getOptions() as $option) {
            if (!$definition->hasOption($option->getName())) {
                $definition->addOption($option);
            }
        }
        $definition->addOption(new InputOption('set', null, InputOption::VALUE_REQUIRED));
        $definition->addOption(new InputOption('reset'));
        try {
            $input->bind($definition);
        } catch (\Exception) {
            // The actual command validates usage and returns a structured error below.
        }
        $name = $input->getFirstArgument();
        if (!\in_array($name, [...self::HUMAN_COMMANDS, ...self::API_COMMANDS], true)) {
            // A value before the command may belong to the API's --profile rather than Symfony's profiler flag.
            $api = CoreDataCommand::forKernel($this->getKernel());
            $definition->setOptions(array_replace($definition->getOptions(), $api->getDefinition()->getOptions()));
            try {
                $input->bind($definition);
            } catch (\Exception) {
                // The command handles validation below.
            }
            $name = $input->getFirstArgument();
            if (!\in_array($name, ['sillbeam:api', 'sillbeam:api:capabilities', 'sillbeam:inspect:sources'], true)) {
                return parent::doRun($input, $output);
            }
        }
        $jsonDoctor = 'sillbeam:doctor' === $name && DoctorCommand::isJsonRequested($input);
        $bufferLevel = ob_get_level();
        if ($jsonDoctor) {
            ob_start();
        }
        $translator = new CoreTranslator();
        try {
            $help = $input->hasParameterOption(['--help', '-h'], true);
            $explicit = self::explicitLocale($input);
            $machine = !$help && ($jsonDoctor || ('sillbeam:architecture' === $name && DoctorCommand::isJsonRequested($input)));
            if (\in_array($name, self::HUMAN_COMMANDS, true)) {
                $translator = new CoreTranslator($machine || (!$help && 'sillbeam:locale' === $name)
                    ? (null === $explicit ? CoreTranslator::DEFAULT_LOCALE : LocaleResolver::requireSupported($explicit))
                    : $this->localeResolver(staticProject: 'sillbeam:doctor' === $name)->resolve($explicit));
            } elseif ($help) {
                if ('sillbeam:api' === $name && null !== $explicit) {
                    LocaleResolver::requireSupported($explicit);
                }
                $translator = $this->apiTranslator('sillbeam:api' === $name ? $explicit : null);
            }
            $command = $this->coreCommand($name, $translator);
            $definition = clone $this->getDefinition();
            $definition->setOptions(array_replace($definition->getOptions(), $command->getDefinition()->getOptions()));
            $application = new ConsoleApplication('Sillbeam');
            $application->setDefinition($definition);
            $application->addCommand($command);
            if ($help) {
                // Render the target directly. Symfony HelpCommand owns its own --format option.
                (new DescriptorHelper())->describe($output, $command, ['format' => 'txt']);

                return 0;
            }

            return $application->doRun($input, $output);
        } catch (\Throwable $error) {
            if ('sillbeam:api' === $name || 'sillbeam:api:capabilities' === $name || 'sillbeam:inspect:sources' === $name) {
                return CoreDataCommand::failure($error, $output, $this->apiTranslator());
            }
            if ('sillbeam:graph' === $name) {
                return GraphCommand::usageError($error, $output, $translator);
            }
            if ('sillbeam:doctor' === $name) {
                return DoctorCommand::failure($error, $error instanceof \Symfony\Component\Console\Exception\ExceptionInterface || $error instanceof \InvalidArgumentException ? 'doctor.usage' : 'doctor.execution', $jsonDoctor, $output, $translator);
            }

            return ArchitectureCommand::usageError($error, 'sillbeam:architecture' === $name && DoctorCommand::isJsonRequested($input), $input, $output, $translator);
        } finally {
            if ($jsonDoctor) {
                while (ob_get_level() > $bufferLevel) {
                    ob_end_clean();
                }
            }
        }
    }

    /**
     * Resolves diagnostic commands before container boot; delegates other names to Symfony.
     *
     * @throws \Symfony\Component\Console\Exception\CommandNotFoundException if Symfony cannot resolve the name
     */
    public function find(string $name): Command
    {
        return \in_array($name, [...self::HUMAN_COMMANDS, ...self::API_COMMANDS], true) ? $this->get($name) : parent::find($name);
    }

    /** Returns exact Core commands without booting the kernel. */
    public function get(string $name): Command
    {
        if (!\in_array($name, [...self::HUMAN_COMMANDS, ...self::API_COMMANDS], true)) {
            return parent::get($name);
        }
        $command = $this->coreCommand($name, new CoreTranslator());
        if ($command instanceof LocalizedCommand && !$command instanceof LocaleCommand) {
            $command->useLocaleResolver($this->localeResolver(staticProject: 'sillbeam:doctor' === $name));
        }

        return $command;
    }

    private function coreCommand(string $name, CoreTranslator $translator, ?string $projectDir = null): Command
    {
        $kernel = $this->getKernel();
        $report = static fn (CoreTranslator $language): ArchitectureReport => (new KernelArchitectureReporter())->report($kernel, $language);
        $creator = static fn (): DescriptorCreator => new DescriptorCreator((new KernelArchitectureReporter())->configuration($kernel)->options);
        $command = match ($name) {
            'sillbeam:api' => CoreDataCommand::forKernel($kernel, $translator, $this->apiTranslator(...)),
            'sillbeam:api:capabilities' => new CoreCapabilitiesCommand(translator: $translator, errorTranslator: $this->apiTranslator(...)),
            'sillbeam:inspect:sources' => new InspectSourcesCommand(CoreDataCommand::providerForKernel($kernel), $translator, $this->apiTranslator(...)),
            'sillbeam:architecture' => new ArchitectureCommand($report, $translator),
            'sillbeam:graph' => new GraphCommand($report, $translator),
            'sillbeam:inspect:module' => new InspectModuleCommand($report, $translator),
            'sillbeam:doctor' => new DoctorCommand($kernel->getProjectDir(), $kernel->getEnvironment(), $kernel, $translator),
            'sillbeam:report' => new ReportCommand($report, $projectDir ?? $kernel->getProjectDir(), $translator),
            'sillbeam:make:module' => new MakeModuleCommand($creator, $translator),
            'sillbeam:make:project' => new MakeProjectCommand($creator, $translator),
            'sillbeam:about' => new AboutCommand(static function () use ($kernel): KernelInformation {
                $kernel->boot();
                $container = $kernel->getContainer();
                $modules = $container->has(ModuleRegistryInterface::class) ? $container->get(ModuleRegistryInterface::class) : null;

                return new KernelInformation($kernel, $modules instanceof ModuleRegistryInterface ? $modules : null);
            }, $translator),
            'sillbeam:locale' => new LocaleCommand($store = UserLocaleStore::fromEnvironment(), $this->localeResolver($store), $translator),
            default => throw new \LogicException('Unknown Core command: '.$name),
        };
        $command->setApplication($this);

        return $command;
    }

    private function localeResolver(?UserLocaleStore $store = null, bool $staticProject = false): LocaleResolver
    {
        return new LocaleResolver($store ?? UserLocaleStore::fromEnvironment(), function () use ($staticProject): ?string {
            $level = ob_get_level();
            ob_start();
            try {
                $kernel = $this->getKernel();

                return $staticProject
                    ? (new InstallationInspector())->projectLocale($kernel->getProjectDir(), $kernel->getEnvironment())
                    : (new KernelArchitectureReporter())->projectLocale($kernel);
            } catch (\Throwable) {
                // Diagnostics can still report the original configuration failure in English.
                return null;
            } finally {
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }
            }
        });
    }

    private function apiTranslator(?string $explicit = null): CoreTranslator
    {
        try {
            return new CoreTranslator($this->localeResolver()->resolve($explicit));
        } catch (\InvalidArgumentException|\RuntimeException) {
            // Presentation preferences cannot change machine command results.
            return new CoreTranslator();
        }
    }

    private static function explicitLocale(InputInterface $input): ?string
    {
        if (!$input instanceof ArgvInput) {
            $value = $input->getParameterOption('--locale', null, true);

            return \is_string($value) ? $value : null;
        }
        $locale = null;
        $tokens = $input->getRawTokens();
        foreach ($tokens as $index => $token) {
            if ('--' === $token) {
                break;
            }
            if ('--locale' === $token) {
                $value = $tokens[$index + 1] ?? '';
                $locale = str_starts_with($value, '-') ? '' : $value;
            } elseif (str_starts_with($token, '--locale=')) {
                $locale = substr($token, \strlen('--locale='));
            }
        }

        return $locale;
    }
}
