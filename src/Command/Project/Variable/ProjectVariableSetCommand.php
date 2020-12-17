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
    protected $stability = 'deprecated';

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
        $this->setHelp(
            'This command is deprecated and will be removed in a future version.'
            . "\nInstead, use <info>variable:create</info> and <info>variable:update</info>"
        );
        $this->addProjectOption()
             ->addWaitOptions();
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
        $this->getSelectedProject()
                       ->setVariable($variableName, $variableValue, $json, !$supressBuild, !$supressRuntime);

        $this->stdErr->writeln("Variable <info>$variableName</info> set to: $variableValue");

        $this->redeployWarning();

        return 0;
    }

    /**
     * @param $string
     *
     * @return bool
     */
    protected function validateJson($string)
    {
        if ($string === 'null') {
            return true;
        }
        return \json_decode($string) !== null;
    }
}
