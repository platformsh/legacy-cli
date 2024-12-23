<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\VariableCommandUtil;
use Platformsh\Client\Model\ProjectLevelVariable;
use Platformsh\Client\Model\Variable;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'variable:list', description: 'List variables', aliases: ['variables', 'var'])]
class VariableListCommand extends CommandBase
{
    /** @var array<string, string> */
    private array $tableHeader = [
        'name' => 'Name',
        'level' => 'Level',
        'value' => 'Value',
        'is_enabled' => 'Enabled',
    ];

    public function __construct(
        private readonly Api                 $api,
        private readonly Config              $config,
        private readonly PropertyFormatter   $propertyFormatter,
        private readonly Selector            $selector,
        private readonly Table               $table,
        private readonly VariableCommandUtil $variableCommandUtil,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->variableCommandUtil->addLevelOption($this->getDefinition());
        Table::configureInput($this->getDefinition(), $this->tableHeader);
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $level = $this->variableCommandUtil->getRequestedLevel($input);

        $selection = $this->selector->getSelection($input, new SelectorConfig(envRequired: $level !== 'project'));

        $project = $selection->getProject();

        $variables = [];
        if ($level === 'project' || $level === null) {
            $variables = array_merge($variables, $project->getVariables());
        }
        if ($level === 'environment' || $level === null) {
            $variables = array_merge($variables, $selection->getEnvironment()->getVariables());
        }

        if (empty($variables)) {
            $this->stdErr->writeln('No variables found.');

            return 1;
        }

        if (!$this->table->formatIsMachineReadable()) {
            $projectLabel = $this->api->getProjectLabel($project);
            switch ($level) {
                case 'project':
                    $this->stdErr->writeln(sprintf('Project-level variables on the project %s:', $projectLabel));
                    break;

                case 'environment':
                    $environmentId = $selection->getEnvironment()->id;
                    $this->stdErr->writeln(sprintf('Environment-level variables on the environment <info>%s</info> of project %s:', $environmentId, $projectLabel));
                    break;

                default:
                    $environmentId = $selection->getEnvironment()->id;
                    $this->stdErr->writeln(sprintf('Variables on the project %s, environment <info>%s</info>:', $projectLabel, $environmentId));
                    break;
            }
        }

        $rows = [];

        /** @var ProjectLevelVariable|Variable $variable */
        foreach ($variables as $variable) {
            $row = [];
            $row['name'] = $variable->name;
            $row['level'] = new AdaptiveTableCell($this->variableCommandUtil->getVariableLevel($variable), ['wrap' => false]);

            // Handle sensitive variables' value (it isn't exposed in the API).
            if (!$variable->hasProperty('value', false) && $variable->is_sensitive) {
                $row['value'] = $this->table->formatIsMachineReadable() ? '' : '<fg=yellow>[Hidden: sensitive value]</>';
            } else {
                $row['value'] = $this->propertyFormatter->format($variable->value, 'value');
            }

            $row['is_enabled'] = $this->propertyFormatter->format($variable->getProperty('is_enabled', false), 'is_enabled');

            $rows[] = $row;
        }

        $this->table->render($rows, $this->tableHeader);

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            $executable = $this->config->getStr('application.executable');
            $this->stdErr->writeln(sprintf(
                'To view variable details, run: <info>%s variable:get [name]</info>',
                $executable,
            ));
            $this->stdErr->writeln(sprintf(
                'To create a new variable, run: <info>%s variable:create</info>',
                $executable,
            ));
            $this->stdErr->writeln(sprintf(
                'To update a variable, run: <info>%s variable:update [name]</info>',
                $executable,
            ));
            $this->stdErr->writeln(sprintf(
                'To delete a variable, run: <info>%s variable:delete [name]</info>',
                $executable,
            ));
        }

        return 0;
    }
}
