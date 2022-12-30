<?php
namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VariableListCommand extends VariableCommandBase
{
    private $tableHeader = [
        'name' => 'Name',
        'level' => 'Level',
        'value' => 'Value',
        'is_enabled' => 'Enabled',
    ];

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('variable:list')
            ->setAliases(['variables', 'var'])
            ->setDescription('List variables');
        $this->addLevelOption();
        Table::configureInput($this->getDefinition(), $this->tableHeader);
        $this->addProjectOption()
             ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $level = $this->getRequestedLevel($input);

        $this->validateInput($input, $level === 'project');

        $project = $this->getSelectedProject();

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');

        $variables = [];
        if ($level === 'project' || $level === null) {
            $variables = array_merge($variables, $project->getVariables());
        }
        if ($level === 'environment' || $level === null) {
            $variables = array_merge($variables, $this->getSelectedEnvironment()->getVariables());
        }

        if (empty($variables)) {
            $this->stdErr->writeln('No variables found.');

            return 1;
        }

        if (!$table->formatIsMachineReadable()) {
            $projectLabel = $this->api()->getProjectLabel($project);
            switch ($level) {
                case 'project':
                    $this->stdErr->writeln(sprintf('Project-level variables on the project %s:', $projectLabel));
                    break;

                case 'environment':
                    $environmentId = $this->getSelectedEnvironment()->id;
                    $this->stdErr->writeln(sprintf('Environment-level variables on the environment <info>%s</info> of project %s:', $environmentId, $projectLabel));
                    break;

                default:
                    $environmentId = $this->getSelectedEnvironment()->id;
                    $this->stdErr->writeln(sprintf('Variables on the project %s, environment <info>%s</info>:', $projectLabel, $environmentId));
                    break;
            }
        }

        $rows = [];

        /** @var \Platformsh\Client\Model\ProjectLevelVariable|\Platformsh\Client\Model\Variable $variable */
        foreach ($variables as $variable) {
            $row = [];
            $row['name'] = $variable->name;
            $row['level'] = new AdaptiveTableCell($this->getVariableLevel($variable), ['wrap' => false]);

            // Handle sensitive variables' value (it isn't exposed in the API).
            if (!$variable->hasProperty('value', false) && $variable->is_sensitive) {
                $row['value'] = $table->formatIsMachineReadable() ? '' : '<fg=yellow>[Hidden: sensitive value]</>';
            } else {
                $row['value'] = $variable->value;
            }

            if ($variable->hasProperty('is_enabled')) {
                $row['is_enabled'] = $variable->is_enabled ? 'true' : 'false';
            } else {
                $row['is_enabled'] = '';
            }

            $rows[] = $row;
        }

        $table->render($rows, $this->tableHeader);

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            $executable = $this->config()->get('application.executable');
            $this->stdErr->writeln(sprintf(
                'To view variable details, run: <info>%s variable:get [name]</info>',
                $executable
            ));
            $this->stdErr->writeln(sprintf(
                'To create a new variable, run: <info>%s variable:create</info>',
                $executable
            ));
            $this->stdErr->writeln(sprintf(
                'To update a variable, run: <info>%s variable:update [name]</info>',
                $executable
            ));
            $this->stdErr->writeln(sprintf(
                'To delete a variable, run: <info>%s variable:delete [name]</info>',
                $executable
            ));
        }

        return 0;
    }
}
