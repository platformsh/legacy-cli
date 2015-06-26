<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentVariableSetCommand extends PlatformCommand
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
          ->setDescription('Set a variable for an environment');
        $this->addProjectOption()
             ->addEnvironmentOption();
        $this->addExample('Set the variable "example" to the string "123"', 'example 123');
        $this->addExample('Set the variable "example" to the Boolean TRUE', 'example --json true');
        $this->addExample('Set the variable "example" to a list of values', 'example --json \'["value1", "value2"]\'');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $variableName = $input->getArgument('name');
        $variableValue = $input->getArgument('value');
        $json = $input->getOption('json');

        if ($json && !$this->validateJson($variableValue)) {
            throw new \Exception("Invalid JSON: <error>$variableValue</error>");
        }

        // Check whether the variable already exists. If there is no change,
        // quit early.
        $existing = $this->getSelectedEnvironment()
                         ->getVariable($variableName);
        if ($existing && $existing->getProperty('value') === $variableValue && $existing->getProperty(
            'is_json'
          ) == $json
        ) {
            $this->stdErr->writeln("Variable <info>$variableName</info> already set as: $variableValue");

            return 0;
        }

        // Set the variable to a new value.
        $variable = $this->getSelectedEnvironment()
                         ->setVariable($variableName, $variableValue, $json);
        if (!$variable) {
            $this->stdErr->writeln("Failed to set variable <error>$variableName</error>");

            return 1;
        }

        $this->stdErr->writeln("Variable <info>$variableName</info> set to: $variableValue");

        if (!$this->getSelectedEnvironment()
                  ->getLastActivity()
        ) {
            $this->rebuildWarning();
        }

        return 0;
    }

    /**
     * @param $string
     *
     * @return bool
     */
    protected function validateJson($string)
    {
        $null = json_decode($string) === null;

        return !$null || ($null && $string === 'null');
    }

}
