<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\SourceOperation;

use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\ApiFeatureMissingException;
use Platformsh\Cli\Model\Variable;
use Platformsh\Client\Exception\OperationUnavailableException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'source-operation:run', description: 'Run a source operation')]
class RunCommand extends CommandBase
{
    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Api $api, private readonly Config $config, private readonly Io $io, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this
            ->addArgument('operation', InputArgument::OPTIONAL, 'The operation name')
            ->addOption('variable', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A variable to set during the operation, in the format <info>type:name=value</info>');

        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->activityMonitor->addWaitOptions($this->getDefinition());

        $this->addExample('Run the "update" operation, setting environment variable FOO=bar', 'update --variable env:FOO=bar');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);

        $variables = $this->parseVariables($input->getOption('variable'));
        $this->io->debug('Parsed variables: ' . json_encode($variables));

        $environment = $selection->getEnvironment();
        $sourceOps = $environment->getSourceOperations();
        if (!$sourceOps) {
            $this->stdErr->writeln('No source operations were found on the environment.');
            return 1;
        }

        $operation = $input->getArgument('operation');
        if (!$operation) {
            if (!$input->isInteractive()) {
                $this->stdErr->writeln('The <error>operation</error> argument is required in non-interactive mode.');
                return 1;
            }
            $choices = [];
            foreach ($sourceOps as $sourceOp) {
                $choices[$sourceOp->operation] = $sourceOp->operation . ' (app: <info>' . $sourceOp->app . '</info>)';
            }
            ksort($choices, SORT_NATURAL);
            $operation = $this->questionHelper->choose($choices, 'Enter a number to choose an operation to run:', null, false);
        }

        $operationNames = [];
        foreach ($sourceOps as $sourceOp) {
            $operationNames[] = $sourceOp->operation;
        }
        if (!in_array($operation, $operationNames, true)) {
            $this->stdErr->writeln(sprintf('The source operation <error>%s</error> was not found on the environment %s.', $operation, $this->api->getEnvironmentLabel($environment, 'comment')));
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf('To list source operations, run: <comment>%s source-ops</comment>', $this->config->getStr('application.executable')));
            return 1;
        }


        try {
            $this->stdErr->writeln(\sprintf('Running source operation <info>%s</info>', $operation));
            $result = $selection->getEnvironment()->runSourceOperation(
                $operation,
                $variables,
            );
        } catch (OperationUnavailableException) {
            throw new ApiFeatureMissingException('This project does not support source operations.');
        }

        $success = true;
        if ($this->activityMonitor->shouldWait($input)) {
            $monitor = $this->activityMonitor;
            $success = $monitor->waitMultiple($result->getActivities(), $selection->getProject());
        }

        if ($success && $this->selector->isProjectCurrent($selection->getProject())) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('You may wish to run <info>git pull</info> to update your local repository.');
        }

        return $success ? 0 : 1;
    }

    /**
     * @param string[] $variables
     *
     * @return array<string, array<string, string>>
     */
    private function parseVariables(array $variables): array
    {
        $map = [];
        $variable = new Variable();
        foreach ($variables as $var) {
            [$type, $name, $value] = $variable->parse($var);
            $map[$type][$name] = $value;
        }

        return $map;
    }
}
