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
        $this->validateInput($input);

        $variableName = $input->getArgument('name');

        $variable = $this->getSelectedEnvironment()
                         ->getVariable($variableName);
        if (!$variable) {
            $this->stdErr->writeln("Variable not found: <error>$variableName</error>");

            return 1;
        }

        if (!$variable->operationAvailable('delete')) {
            if ($variable->getProperty('inherited')) {
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

        $environmentId = $this->getSelectedEnvironment()['id'];
        $confirm = $this->getHelper('question')
                        ->confirm(
                          "Delete the variable <info>$variableName</info> from the environment <info>$environmentId</info>?",
                          $input,
                          $this->stdErr,
                          false
                        );

        if (!$confirm) {
            return 1;
        }

        $variable->delete();

        $this->stdErr->writeln("Deleted variable <info>$variableName</info>");
        if (!$this->getSelectedEnvironment()
                  ->getLastActivity()
        ) {
            $this->rebuildWarning();
        }

        return 0;
    }

}
