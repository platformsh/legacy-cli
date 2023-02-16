<?php

namespace Platformsh\Cli\Command\SourceOperation;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\ApiFeatureMissingException;
use Platformsh\Cli\Model\Variable;
use Platformsh\Client\Exception\OperationUnavailableException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RunCommand extends CommandBase
{
    protected function configure()
    {
        $this->setName('source-operation:run')
            ->setDescription('Run a source operation')
            ->addArgument('operation', InputArgument::REQUIRED, 'The operation name')
            ->addOption('variable', null, InputOption::VALUE_REQUIRED|InputOption::VALUE_IS_ARRAY, 'A variable to set during the operation, in the format <info>type:name=value</info>');

        $this->addProjectOption();
        $this->addEnvironmentOption();
        $this->addWaitOptions();

        $this->addExample('Run the "update" operation, setting environment variable FOO=bar', 'update --variable env:FOO=bar');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $variables = $this->parseVariables($input->getOption('variable'));
        $this->debug('Parsed variables: ' . json_encode($variables));

        $operation = $input->getArgument('operation');

        $environment = $this->getSelectedEnvironment();
        $sourceOps = $environment->getSourceOperations();
        if (!$sourceOps) {
            $this->stdErr->writeln('No source operations were found on the environment.');
            return 1;
        }
        $operationNames = [];
        foreach ($sourceOps as $sourceOp) {
            $operationNames[] = $sourceOp->operation;
        }
        if (!in_array($operation, $operationNames, true)) {
            $this->stdErr->writeln(sprintf('The source operation <error>%s</error> was not found on the environment %s.', $operation, $this->api()->getEnvironmentLabel($environment, 'comment')));
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf('To list source operations, run: <comment>%s source-ops</comment>', $this->config()->get('application.executable')));
            return 1;
        }


        try {
            $result = $this->getSelectedEnvironment()->runSourceOperation(
                $operation,
                $variables
            );
        } catch (OperationUnavailableException $e) {
            throw new ApiFeatureMissingException('This project does not support source operations.');
        }

        $success = true;
        if ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $monitor */
            $monitor = $this->getService('activity_monitor');
            $success = $monitor->waitMultiple($result->getActivities(), $this->getSelectedProject());
        }

        return $success ? 0 : 1;
    }

    /**
     * @param array $variables
     *
     * @return array
     */
    private function parseVariables(array $variables)
    {
        $map = [];
        $variable = new Variable();
        foreach ($variables as $var) {
            list($type, $name, $value) = $variable->parse($var);
            $map[$type][$name] = $value;
        }

        return $map;
    }
}
