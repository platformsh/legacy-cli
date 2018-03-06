<?php
namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Variable as EnvironmentLevelVariable;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VariableListCommand extends VariableCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('variable:list')
            ->setAliases(['variables'])
            ->setDescription('List variables');
        $this->addLevelOption();
        Table::configureInput($this->getDefinition());
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
            $row[] = $variable->name;
            $row[] = new AdaptiveTableCell($this->getVariableLevel($variable), ['wrap' => false]);

            // Handle sensitive variables' value (it isn't exposed in the API).
            if ($variable instanceof EnvironmentLevelVariable && !$variable->hasProperty('value') && $variable->is_sensitive) {
                $row[] = '[sensitive]';
            } else {
                $row[] = wordwrap($variable->value, 40, "\n", true);
            }

            $rows[] = $row;
        }

        $table->render($rows, ['Name', 'Level', 'Value']);

        return 0;
    }
}
