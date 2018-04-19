<?php
namespace Platformsh\Cli\Command\Variable;

use Platformsh\Client\Model\Variable;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VariableDeleteCommand extends VariableCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('variable:delete')
            ->addArgument('name', InputArgument::REQUIRED, 'The variable name')
            ->setDescription('Delete a variable');
        $this->addLevelOption();
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addWaitOptions();
        $this->addExample('Delete the variable "example"', 'example');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $level = $this->getRequestedLevel($input);
        $this->validateInput($input, $level === self::LEVEL_PROJECT);

        $variableName = $input->getArgument('name');

        $variable = $this->getExistingVariable($variableName, $level);
        if (!$variable) {
            return 1;
        }

        if (!$this->api()->checkEnvironmentOperation('delete', $this->getSelectedEnvironment())) {
            if ($variable instanceof Variable && $variable->inherited) {
                $this->stdErr->writeln(
                    "The variable <error>$variableName</error> is inherited,"
                    . " so it cannot be deleted from this environment."
                    . "\nYou could override its value with the <comment>variable:update</comment> command."
                );
            } else {
                $this->stdErr->writeln("The variable <error>$variableName</error> cannot be deleted");
            }

            return 1;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        switch ($this->getVariableLevel($variable)) {
            case 'environment':
                $environmentId = $this->getSelectedEnvironment()->id;
                $confirm = $questionHelper->confirm(
                    "Are you sure you want to delete the variable <info>$variableName</info> from the environment <info>$environmentId</info>?",
                    false
                );
                if (!$confirm) {
                    return 1;
                }
                break;

            case 'project':
                $confirm = $questionHelper->confirm(
                    "Are you sure you want to delete the variable <info>$variableName</info> from the project " . $this->api()->getProjectLabel($this->getSelectedProject()) . "?",
                    false
                );
                if (!$confirm) {
                    return 1;
                }
                break;
        }

        $result = $variable->delete();

        $this->stdErr->writeln("Deleted variable <info>$variableName</info>");

        $success = true;
        if (!$result->countActivities()) {
            $this->redeployWarning();
        } elseif ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $success = $activityMonitor->waitMultiple($result->getActivities(), $this->getSelectedProject());
        }

        return $success ? 0 : 1;
    }
}
