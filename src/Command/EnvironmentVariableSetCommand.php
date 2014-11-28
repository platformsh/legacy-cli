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
            ->setAliases(array('vset'))
            ->addArgument('name', InputArgument::REQUIRED, 'The variable name')
            ->addArgument('value', InputArgument::REQUIRED, 'The variable value')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Mark the value as JSON')
            ->addOption('project', null, InputOption::VALUE_OPTIONAL, 'The project ID')
            ->addOption('environment', null, InputOption::VALUE_OPTIONAL, 'The environment ID')
            ->setDescription('Set a variable for an environment');
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

        if ($json && !$this->validateJson($variableValue)) {
            throw new \Exception("Invalid JSON: <error>$variableValue</error>");
        }

        // Check whether the variable already exists. If there is no change,
        // quit early.
        $existing = $environment->getVariable($variableName);
        if ($existing && $existing->getProperty('value') === $variableValue && $existing->getProperty('is_json') == $json) {
            $output->writeln("$variableName already set to <info>$variableValue</info>");
            return 0;
        }

        // Set the variable to a new value.
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

    /**
     * @param $string
     * @return bool
     */
    protected function validateJson($string)
    {
        $null = json_decode($string) === null;
        return !$null || ($null && $string === 'null');
    }

}
