<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Environment;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class EnvironmentVariableSetCommand extends EnvironmentCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('variable:set')
            ->addArgument('name', InputArgument::REQUIRED, 'The variable name')
            ->addArgument('value', InputArgument::REQUIRED, 'The variable value')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Mark the value as JSON')
            ->addOption('project', null, InputOption::VALUE_OPTIONAL, 'The project ID')
            ->addOption('environment', null, InputOption::VALUE_OPTIONAL, 'The environment ID')
            ->setDescription('Set a variable for an environment.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $environment = new Environment($this->environment);
        $environment->setClient($this->getPlatformClient($this->environment['endpoint']));

        $variableName = $input->getArgument('name');
        $variableValue = $input->getArgument('value');
        $json = $input->getOption('json');

        if ($json && $variableValue && json_decode($variableValue) === null) {
            throw new \Exception("Invalid JSON: <error>$variableValue</error>");
        }

        /** @var \CommerceGuys\Platform\Cli\Model\Resource|false $variable */
        $variable = $environment->getVariable($variableName);
        if ($variable && $variable->getProperty('value') === $variableValue && $variable->getProperty('is_json') == $json) {
            $output->writeln("$variableName already set to <info>$variableValue</info>");
            return 0;
        }

        $variable = $environment->setVariable($variableName, $variableValue, $json);

        if (!$variable) {
            $output->writeln("Failed to set variable <error>$variableName</error>");
            return 1;
        }

        $output->writeln("$variableName set to <info>$variableValue</info>");

        if (!$variable->hasActivity()) {
            $output->writeln(
              "<comment>The environment must be rebuilt for the variable change to take effect</comment>"
            );
        }
        return 0;
    }

}
