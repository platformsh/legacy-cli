<?php
namespace Platformsh\Cli\Command\Project\Variable;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @deprecated Use variable:create and variable:update instead (with --level project)
 */
class ProjectVariableSetCommand extends CommandBase
{
    protected $hiddenInList = true;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('project:variable:set')
            ->setAliases(['pvset'])
            ->addArgument('name', InputArgument::REQUIRED, 'The variable name')
            ->addArgument('value', InputArgument::REQUIRED, 'The variable value')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Mark the value as JSON')
            ->addOption('no-visible-build', null, InputOption::VALUE_NONE, 'Do not expose this variable at build time')
            ->addOption('no-visible-runtime', null, InputOption::VALUE_NONE, 'Do not expose this variable at runtime')
            ->setDescription('Set a variable for a project');
        $this->addProjectOption()
             ->addWaitOptions();
        $this->addExample('Set the variable "example" to the string "123"', 'example 123');
        $this->addExample('Set the variable "example" to the Boolean TRUE', 'example --json true');
        $this->addExample('Set the variable "example" to a list of values', 'example --json \'["value1", "value2"]\'');
        $this->addExample('Set the variable "example" to the string "abc", but only at build time', 'example abc --no-visible-runtime');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $variableName = $input->getArgument('name');
        $variableValue = $input->getArgument('value');
        $json = $input->getOption('json');
        $supressBuild = $input->getOption('no-visible-build');
        $supressRuntime = $input->getOption('no-visible-runtime');

        if ($json && !$this->validateJson($variableValue)) {
            throw new \Exception("Invalid JSON: <error>$variableValue</error>");
        }

        // Check whether the variable already exists. If there is no change,
        // quit early.
        $existing = $this->getSelectedProject()
                         ->getVariable($variableName);
        if ($existing && $existing->value === $variableValue && $existing->is_json == $json) {
            $this->stdErr->writeln("Variable <info>$variableName</info> already set as: $variableValue");

            return 0;
        }

        // Set the variable to a new value.
        $result = $this->getSelectedProject()
                       ->setVariable($variableName, $variableValue, $json, !$supressBuild, !$supressRuntime);

        $this->stdErr->writeln("Variable <info>$variableName</info> set to: $variableValue");

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
