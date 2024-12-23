<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Project\Variable;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @deprecated Use variable:create and variable:update instead (with --level project)
 */
#[AsCommand(name: 'project:variable:set', description: 'Set a variable for a project', aliases: ['pvset'])]
class ProjectVariableSetCommand extends CommandBase
{
    protected bool $hiddenInList = true;
    protected string $stability = 'deprecated';

    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Api $api, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The variable name')
            ->addArgument('value', InputArgument::REQUIRED, 'The variable value')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Mark the value as JSON')
            ->addOption('no-visible-build', null, InputOption::VALUE_NONE, 'Do not expose this variable at build time')
            ->addOption('no-visible-runtime', null, InputOption::VALUE_NONE, 'Do not expose this variable at runtime');
        $this->setHelp(
            'This command is deprecated and will be removed in a future version.'
            . "\nInstead, use <info>variable:create</info> and <info>variable:update</info>",
        );
        $this->selector->addProjectOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->activityMonitor->addWaitOptions($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);

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
        $existing = $selection->getProject()
                         ->getVariable($variableName);
        if ($existing && $existing->value === $variableValue && $existing->is_json == $json) {
            $this->stdErr->writeln("Variable <info>$variableName</info> already set as: $variableValue");

            return 0;
        }

        // Set the variable to a new value.
        $selection->getProject()
                       ->setVariable($variableName, $variableValue, $json, !$supressBuild, !$supressRuntime);

        $this->stdErr->writeln("Variable <info>$variableName</info> set to: $variableValue");

        $this->api->redeployWarning();

        return 0;
    }

    protected function validateJson(string $string): bool
    {
        if ($string === 'null') {
            return true;
        }
        return \json_decode($string) !== null;
    }
}
