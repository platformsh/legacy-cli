<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Environment;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class EnvironmentVariableDeleteCommand extends EnvironmentCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
          ->setName('variable:delete')
          ->addArgument('name', InputArgument::REQUIRED, 'The variable name')
          ->addOption('project', null, InputOption::VALUE_OPTIONAL, 'The project ID')
          ->addOption('environment', null, InputOption::VALUE_OPTIONAL, 'The environment ID')
          ->setDescription('Delete a variable from an environment.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $environment = new Environment($this->environment);
        $environment->setClient($this->getPlatformClient($this->environment['endpoint']));

        $variableName = $input->getArgument('name');

        $variable = $environment->getVariable($variableName);
        if (!$variable) {
            $output->writeln("Variable not found: <error>$variableName</error>");
            return 1;
        }

        /** @var \CommerceGuys\Platform\Cli\Model\Resource $variable */

        if (!$variable->operationAllowed('delete')) {
            $output->writeln("The variable <error>$variableName</error> cannot be deleted from this environment.");
            return 1;
        }

        if (!$this->getHelper('question')->confirm("Are you sure you want to delete the variable <info>$variableName</info>?", $input, $output, false)) {
            return 1;
        }

        $variable->delete();

        $output->writeln("Deleted variable <info>$variableName</info>");
        if (!$variable->hasActivity()) {
            $output->writeln(
              "<comment>The environment must be rebuilt for the variable change to take effect</comment>"
            );
        }
        return 0;
    }

}
