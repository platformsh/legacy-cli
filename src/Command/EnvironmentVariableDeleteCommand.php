<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentVariableDeleteCommand extends PlatformCommand
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
             ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, $output);

        $variableName = $input->getArgument('name');

        $variable = $this->getSelectedEnvironment()
                         ->getVariable($variableName);
        if (!$variable) {
            $output->writeln("Variable not found: <error>$variableName</error>");

            return 1;
        }

        $environmentId = $this->getSelectedEnvironment()['id'];
        $confirmQuestionText = "Delete the variable <info>$variableName</info> from the environment <info>$environmentId</info>?";

        // Inherited variables cannot be deleted, but they can be disabled.
        if ($variable->getProperty('inherited') && isset($variable['is_enabled'])) {
            if (!$variable['is_enabled']) {
                $output->writeln("The variable <info>$variableName</info> is already disabled.");

                return 0;
            }
            $confirmQuestionText = "Disable the variable <info>$variableName</info> for the environment <info>$environmentId</info>?";
        } elseif (!$variable->operationAvailable('delete')) {
            $output->writeln("The variable <error>$variableName</error> cannot be deleted");

            return 1;
        }

        $confirm = $this->getHelper('question')
                        ->confirm($confirmQuestionText, $input, $output);

        if (!$confirm) {
            return 1;
        }

        if ($variable->getProperty('inherited')) {
            $variable->disable();
            $output->writeln("Disabled variable <info>$variableName</info>");
        }
        else {
            $variable->delete();
            $output->writeln("Deleted variable <info>$variableName</info>");
        }

        if (!$this->getSelectedEnvironment()->getLastActivity()) {
            $this->rebuildWarning($output);
        }

        return 0;
    }

}
