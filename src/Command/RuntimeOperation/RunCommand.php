<?php

namespace Platformsh\Cli\Command\RuntimeOperation;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\ApiFeatureMissingException;
use Platformsh\Client\Exception\OperationUnavailableException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @see \Platformsh\Cli\Command\SourceOperation\RunCommand
 */
class RunCommand extends CommandBase
{
    protected $stability = self::STABILITY_BETA;

    protected function configure()
    {
        $this->setName('operation:run')
            ->setDescription('Run an operation on the environment')
            ->addArgument('operation', InputArgument::OPTIONAL, 'The operation name');

        $this->addProjectOption();
        $this->addEnvironmentOption();
        $this->addAppOption();
        $this->addOption('worker', null, InputOption::VALUE_REQUIRED, 'A worker name');
        $this->addWaitOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->chooseEnvFilter = $this->filterEnvsByState(['active']);
        $this->validateInput($input);

        $environment = $this->getSelectedEnvironment();
        $deployment = $this->api()->getCurrentDeployment($environment);

        try {
            if ($input->getOption('app') || $input->getOption('worker')) {
                $selectedApp = $this->selectRemoteContainer($input);
                $appName = $selectedApp->getName();
                $operations = [
                    $selectedApp->getName() => $selectedApp->getRuntimeOperations(),
                ];
            } else {
                $appName = null;
                $operations = $deployment->getRuntimeOperations();
            }
        } catch (OperationUnavailableException $e) {
            throw new ApiFeatureMissingException('This project does not support runtime operations.');
        }

        if (!count($operations)) {
            $this->stdErr->writeln('No runtime operations found.');

            return 0;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

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
            $operationName = $questionHelper->choose($choices, 'Enter a number to choose an operation to run:', null, false);
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
                    $this->stdErr->writeln(sprintf('The runtime operation <error>%s</error> was not found on the environment %s, app <comment>%s</comment>.', $operationName, $this->api()->getEnvironmentLabel($environment, 'comment'), $appName));
                } else {
                    $this->stdErr->writeln(sprintf('The runtime operation <error>%s</error> was not found on the environment %s.', $operationName, $this->api()->getEnvironmentLabel($environment, 'comment')));
                }
                $this->stdErr->writeln('');
                $this->stdErr->writeln(sprintf('To list operations, run: <comment>%s ops</comment>', $this->config()->get('application.executable')));
                return 1;
            }
        }

        if ($appName !== null) {
            $this->stdErr->writeln(\sprintf('Running operation <info>%s</info> on app <info>%s</info>', $operationName, $appName));
        } else {
            $this->stdErr->writeln(\sprintf('Running operation <info>%s</info> on the environment <info>%s</info>', $operationName, $appName));
        }
        if (!$questionHelper->confirm('Are you sure you want to continue?')) {
            return 1;
        }

        try {
            $result = $deployment->execRuntimeOperation($operationName, $appName);
        } catch (OperationUnavailableException $e) {
            throw new ApiFeatureMissingException('This project does not support runtime operations.');
        }

        $success = true;
        if ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $monitor */
            $monitor = $this->getService('activity_monitor');
            $success = $monitor->waitMultiple($result->getActivities(), $this->getSelectedProject());
        }

        return $success ? 0 : 1;
    }
}
