<?php
namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @deprecated Use variable:create and variable:update instead (with --level environment)
 */
class VariableSetCommand extends CommandBase
{
    protected $hiddenInList = true;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('variable:set')
            ->setAliases(['vset'])
            ->addArgument('name', InputArgument::REQUIRED, 'The variable name')
            ->addArgument('value', InputArgument::REQUIRED, 'The variable value')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Mark the value as JSON')
            ->addOption('disabled', null, InputOption::VALUE_NONE, 'Mark the variable as disabled')
            ->setDescription('Set a variable for an environment');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addWaitOptions();
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
        $enabled = !$input->getOption('disabled');

        if ($json && !$this->validateJson($variableValue)) {
            throw new InvalidArgumentException("Invalid JSON: <error>$variableValue</error>");
        }

        // Check whether the variable already exists. If there is no change,
        // quit early.
        $existing = $this->getSelectedEnvironment()
                         ->getVariable($variableName);
        if ($existing
            && $existing->value === $variableValue
            && $existing->is_enabled === $enabled
            && $existing->is_json == $json) {
            $this->stdErr->writeln("Variable <info>$variableName</info> already set as: $variableValue");

            return 0;
        }

        // Set the variable to a new value.
        $result = $this->getSelectedEnvironment()
                       ->setVariable($variableName, $variableValue, $json, $enabled);

        $this->stdErr->writeln("Variable <info>$variableName</info> set to: $variableValue");

        $success = true;
        if ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $success = $activityMonitor->waitMultiple($result->getActivities(), $this->getSelectedProject());
        }

        return $success ? 0 : 1;
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
