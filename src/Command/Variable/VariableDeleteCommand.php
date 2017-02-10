<?php
namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VariableDeleteCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('variable:delete')
            ->addArgument('name', InputArgument::REQUIRED, 'The variable name')
            ->setDescription('Delete a variable from an environment');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addNoWaitOption();
        $this->addExample('Delete the variable "example"', 'example');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $variableName = $input->getArgument('name');

        $variable = $this->getSelectedEnvironment()
                         ->getVariable($variableName);
        if (!$variable) {
            $this->stdErr->writeln("Variable not found: <error>$variableName</error>");

            return 1;
        }

        if (!$variable->operationAvailable('delete')) {
            if ($variable->inherited) {
                $this->stdErr->writeln(
                    "The variable <error>$variableName</error> is inherited,"
                    . " so it cannot be deleted from this environment."
                    . "\nYou could override it with the <comment>variable:set</comment> command."
                );
            } else {
                $this->stdErr->writeln("The variable <error>$variableName</error> cannot be deleted");
            }

            return 1;
        }

        $environmentId = $this->getSelectedEnvironment()->id;
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $confirm = $questionHelper->confirm(
            "Delete the variable <info>$variableName</info> from the environment <info>$environmentId</info>?",
            false
        );
        if (!$confirm) {
            return 1;
        }

        $result = $variable->delete();

        $this->stdErr->writeln("Deleted variable <info>$variableName</info>");

        $success = true;
        if (!$result->countActivities()) {
            $this->rebuildWarning();
        } elseif (!$input->getOption('no-wait')) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $success = $activityMonitor->waitMultiple($result->getActivities(), $this->getSelectedProject());
        }

        return $success ? 0 : 1;
    }
}
