<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\RuntimeOperation;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\ApiFeatureMissingException;
use Platformsh\Client\Exception\OperationUnavailableException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @see \Platformsh\Cli\Command\SourceOperation\RunCommand
 */
#[AsCommand(name: 'operation:run', description: 'Run an operation on the environment')]
class RunCommand extends CommandBase
{
    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Api $api, private readonly Config $config, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this
            ->addArgument('operation', InputArgument::OPTIONAL, 'The operation name');

        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->selector->addAppOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->addOption('worker', null, InputOption::VALUE_REQUIRED, 'A worker name');
        $this->activityMonitor->addWaitOptions($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(chooseEnvFilter: SelectorConfig::filterEnvsMaybeActive()));

        $environment = $selection->getEnvironment();
        $deployment = $this->api->getCurrentDeployment($environment);

        try {
            if ($input->getOption('app') || $input->getOption('worker')) {
                $selectedApp = $selection->getRemoteContainer();
                $appName = $selectedApp->getName();
                $operations = [
                    $selectedApp->getName() => $selectedApp->getRuntimeOperations(),
                ];
            } else {
                $appName = null;
                $operations = $deployment->getRuntimeOperations();
            }
        } catch (OperationUnavailableException) {
            throw new ApiFeatureMissingException('This project does not support runtime operations.');
        }

        if (!count($operations)) {
            $this->stdErr->writeln('No runtime operations found.');

            return 0;
        }

        $operationName = $input->getArgument('operation');
        if (!$operationName) {
            if (!$input->isInteractive()) {
                $this->stdErr->writeln('The <error>operation</error> argument is required in non-interactive mode.');
                return 1;
            }
            $choices = [];
            $appNamesByOperationName = [];
            foreach ($operations as $serviceName => $serviceOperations) {
                foreach ($serviceOperations as $name => $op) {
                    if (isset($choices[$name])) {
                        $this->stdErr->writeln('More than one operation is defined with the same name. Specify an <error></error>--app</error> or a <error>--worker</error>.');
                        return 1;
                    }
                    $choices[$name] = sprintf('%s (on %s)', $name, $serviceName);
                    $appNamesByOperationName[$name] = $serviceName;
                }
            }
            ksort($choices, SORT_NATURAL);
            $operationName = $this->questionHelper->choose($choices, 'Enter a number to choose an operation to run:', null, false);
            $appName = $appNamesByOperationName[$operationName];
        } else {
            $found = false;
            foreach ($operations as $serviceName => $serviceOperations) {
                foreach ($serviceOperations as $name => $op) {
                    if ($name === $operationName) {
                        $appName = $serviceName;
                        $found = true;
                    }
                }
            }
            if (!$found) {
                if ($appName !== null) {
                    $this->stdErr->writeln(sprintf('The runtime operation <error>%s</error> was not found on the environment %s, app <comment>%s</comment>.', $operationName, $this->api->getEnvironmentLabel($environment, 'comment'), $appName));
                } else {
                    $this->stdErr->writeln(sprintf('The runtime operation <error>%s</error> was not found on the environment %s.', $operationName, $this->api->getEnvironmentLabel($environment, 'comment')));
                }
                $this->stdErr->writeln('');
                $this->stdErr->writeln(sprintf('To list operations, run: <comment>%s ops</comment>', $this->config->getStr('application.executable')));
                return 1;
            }
        }

        if ($appName !== null) {
            $this->stdErr->writeln(\sprintf('Running operation <info>%s</info> on app <info>%s</info>', $operationName, $appName));
        } else {
            $this->stdErr->writeln(\sprintf('Running operation <info>%s</info> on the environment <info>%s</info>', $operationName, $appName));
        }
        if (!$this->questionHelper->confirm('Are you sure you want to continue?')) {
            return 1;
        }

        try {
            $result = $deployment->execRuntimeOperation($operationName, $appName);
        } catch (OperationUnavailableException) {
            throw new ApiFeatureMissingException('This project does not support runtime operations.');
        }

        $success = true;
        if ($this->activityMonitor->shouldWait($input)) {
            $monitor = $this->activityMonitor;
            $success = $monitor->waitMultiple($result->getActivities(), $selection->getProject());
        }

        return $success ? 0 : 1;
    }
}
