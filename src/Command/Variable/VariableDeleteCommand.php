<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\VariableCommandUtil;
use Platformsh\Client\Model\Variable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'variable:delete', description: 'Delete a variable')]
class VariableDeleteCommand extends CommandBase
{
    public function __construct(
        private readonly ActivityMonitor  $activityMonitor,
        private readonly Api              $api,
        private readonly QuestionHelper   $questionHelper,
        private readonly Selector         $selector,
        private readonly VariableCommandUtil $variableCommandUtil,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The variable name');
        $this->variableCommandUtil->addLevelOption($this->getDefinition());
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->activityMonitor->addWaitOptions($this->getDefinition());
        $this->addExample('Delete the variable "example"', 'example');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $level = $this->variableCommandUtil->getRequestedLevel($input);
        $selection = $this->selector->getSelection($input, new SelectorConfig(envRequired: $level !== VariableCommandUtil::LEVEL_PROJECT));

        $variableName = $input->getArgument('name');

        $variable = $this->variableCommandUtil->getExistingVariable($variableName, $selection, $level);
        if (!$variable) {
            return 1;
        }

        if (!$variable->operationAvailable('delete')) {
            if ($variable instanceof Variable && $variable->inherited) {
                $this->stdErr->writeln(
                    "The variable <error>$variableName</error> is inherited,"
                    . " so it cannot be deleted from this environment."
                    . "\nYou could override its value with the <comment>variable:update</comment> command.",
                );
            } else {
                $this->stdErr->writeln("The variable <error>$variableName</error> cannot be deleted");
            }

            return 1;
        }

        switch ($this->variableCommandUtil->getVariableLevel($variable)) {
            case 'environment':
                $environmentId = $selection->getEnvironment()->id;
                $confirm = $this->questionHelper->confirm(
                    "Are you sure you want to delete the variable <info>$variableName</info> from the environment <info>$environmentId</info>?",
                    false,
                );
                if (!$confirm) {
                    return 1;
                }
                break;

            case 'project':
                $confirm = $this->questionHelper->confirm(
                    "Are you sure you want to delete the variable <info>$variableName</info> from the project " . $this->api->getProjectLabel($selection->getProject()) . "?",
                    false,
                );
                if (!$confirm) {
                    return 1;
                }
                break;
        }

        $result = $variable->delete();

        $this->stdErr->writeln("Deleted variable <info>$variableName</info>");

        $success = true;
        if (!$result->countActivities() || $level === VariableCommandUtil::LEVEL_PROJECT) {
            $this->api->redeployWarning();
        } elseif ($this->activityMonitor->shouldWait($input)) {
            $activityMonitor = $this->activityMonitor;
            $success = $activityMonitor->waitMultiple($result->getActivities(), $selection->getProject());
        }

        return $success ? 0 : 1;
    }
}
