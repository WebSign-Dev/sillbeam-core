<?php

declare(strict_types=1);

namespace Sillbeam\Core\Console;

use Sillbeam\Core\Architecture\ArchitectureReport;
use Sillbeam\Core\Architecture\Console\ArchitectureCommand;
use Sillbeam\Core\Architecture\Console\GraphCommand;
use Sillbeam\Core\Architecture\Console\InspectModuleCommand;
use Sillbeam\Core\Architecture\Console\ReportCommand;
use Sillbeam\Core\Console\Api\CoreCapabilitiesCommand;
use Sillbeam\Core\Console\Api\CoreDataCommand;
use Sillbeam\Core\Console\Api\InspectSourcesCommand;
use Sillbeam\Core\Console\Doctor\DoctorCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Input\InputInterface;
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
    /**
     * Dispatches diagnostic commands without booting the container and delegates other commands to Symfony.
     *
     * Writes command/help/error output; analysis uses status codes 0, 1 and 2.
     * Graph invocation and renderer/output failures use 3 and 4 respectively.
     */
    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        $command = $this->architectureCommand();
        $definition = clone $this->getDefinition();
        foreach ($command->getDefinition()->getOptions() as $option) {
            $definition->addOption($option);
        }
        // Read option metadata without resolving a kernel-derived output directory.
        $definition->addOption($this->reportCommand('')->getDefinition()->getOption('output'));
        $definition->addOption($this->inspectModuleCommand()->getDefinition()->getOption('project'));
        foreach ($this->graphCommand()->getDefinition()->getOptions() as $option) {
            if (!$definition->hasOption($option->getName())) {
                $definition->addOption($option);
            }
        }
        try {
            $input->bind($definition);
        } catch (\Exception) {
            // The actual command validates usage and returns a structured error below.
        }
        $name = $input->getFirstArgument();
        if (!\in_array($name, ['sillbeam:architecture', 'sillbeam:report', 'sillbeam:doctor', 'sillbeam:inspect:module', 'sillbeam:graph', 'sillbeam:api', 'sillbeam:api:capabilities', 'sillbeam:inspect:sources'], true)) {
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
        try {
            $command = match ($name) {
                'sillbeam:api' => $api ?? CoreDataCommand::forKernel($this->getKernel()),
                'sillbeam:api:capabilities' => new CoreCapabilitiesCommand(),
                'sillbeam:inspect:sources' => new InspectSourcesCommand(CoreDataCommand::providerForKernel($this->getKernel())),
                'sillbeam:doctor' => $this->doctorCommand(),
                'sillbeam:inspect:module' => $this->inspectModuleCommand(),
                'sillbeam:graph' => $this->graphCommand(),
                'sillbeam:report' => $this->reportCommand(),
                default => $command,
            };
            if ($input->hasParameterOption(['--help', '-h'], true)) {
                // HelpCommand has its own --format option: avoid merging report options into it.
                (new DescriptorHelper())->describe($output, $command, ['format' => 'txt']);

                return 0;
            }
            $definition = clone $this->getDefinition();
            $definition->setOptions(array_replace($definition->getOptions(), $command->getDefinition()->getOptions()));
            $application = new ConsoleApplication('Sillbeam');
            $application->setDefinition($definition);
            $application->addCommand($command);

            return $application->doRun($input, $output);
        } catch (\Throwable $error) {
            if ('sillbeam:api' === $name || 'sillbeam:api:capabilities' === $name || 'sillbeam:inspect:sources' === $name) {
                return CoreDataCommand::failure($error, $output);
            }
            if ('sillbeam:graph' === $name) {
                return GraphCommand::usageError($error, $output);
            }
            if ('sillbeam:doctor' === $name) {
                return DoctorCommand::failure($error, $error instanceof \Symfony\Component\Console\Exception\ExceptionInterface ? 'doctor.usage' : 'doctor.execution', DoctorCommand::isJsonRequested($input), $output);
            }

            return ArchitectureCommand::usageError($error, 'sillbeam:architecture' === $name && 'json' === $input->getParameterOption('--format'), $input, $output);
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
        return match ($name) {
            'sillbeam:api' => CoreDataCommand::forKernel($this->getKernel()),
            'sillbeam:api:capabilities' => new CoreCapabilitiesCommand(),
            'sillbeam:inspect:sources' => new InspectSourcesCommand(CoreDataCommand::providerForKernel($this->getKernel())),
            'sillbeam:architecture' => $this->architectureCommand(),
            'sillbeam:report' => $this->reportCommand(),
            'sillbeam:doctor' => $this->doctorCommand(),
            'sillbeam:inspect:module' => $this->inspectModuleCommand(),
            'sillbeam:graph' => $this->graphCommand(),
            default => parent::find($name),
        };
    }

    /**
     * Returns a diagnostic command without container boot; delegates other names to Symfony.
     *
     * @throws \Symfony\Component\Console\Exception\CommandNotFoundException if Symfony cannot resolve the name
     */
    public function get(string $name): Command
    {
        return match ($name) {
            'sillbeam:api' => CoreDataCommand::forKernel($this->getKernel()),
            'sillbeam:api:capabilities' => new CoreCapabilitiesCommand(),
            'sillbeam:inspect:sources' => new InspectSourcesCommand(CoreDataCommand::providerForKernel($this->getKernel())),
            'sillbeam:architecture' => $this->architectureCommand(),
            'sillbeam:report' => $this->reportCommand(),
            'sillbeam:doctor' => $this->doctorCommand(),
            'sillbeam:inspect:module' => $this->inspectModuleCommand(),
            'sillbeam:graph' => $this->graphCommand(),
            default => parent::get($name),
        };
    }

    private function graphCommand(): GraphCommand
    {
        $command = new GraphCommand(fn (): ArchitectureReport => (new KernelArchitectureReporter())->report($this->getKernel()));
        $command->setApplication($this);

        return $command;
    }

    private function inspectModuleCommand(): InspectModuleCommand
    {
        $command = new InspectModuleCommand(fn (): ArchitectureReport => (new KernelArchitectureReporter())->report($this->getKernel()));
        $command->setApplication($this);

        return $command;
    }

    private function doctorCommand(): DoctorCommand
    {
        $command = new DoctorCommand($this->getKernel()->getProjectDir(), $this->getKernel()->getEnvironment(), $this->getKernel());
        $command->setApplication($this);

        return $command;
    }

    private function reportCommand(?string $projectDir = null): ReportCommand
    {
        $command = new ReportCommand(fn (): ArchitectureReport => (new KernelArchitectureReporter())->report($this->getKernel()), $projectDir ?? $this->getKernel()->getProjectDir());
        $command->setApplication($this);

        return $command;
    }

    private function architectureCommand(): ArchitectureCommand
    {
        $command = new ArchitectureCommand(fn (): ArchitectureReport => (new KernelArchitectureReporter())->report($this->getKernel()));
        $command->setApplication($this);

        return $command;
    }
}
