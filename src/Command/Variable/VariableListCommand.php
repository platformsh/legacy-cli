<?php
namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\ProjectLevelVariable;
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
        Table::configureInput($this->getDefinition());
        $this->addProjectOption()
             ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, true);

        $variables = $this->getSelectedProject()->getVariables();

        if ($this->hasSelectedEnvironment()) {
            $variables = array_merge($variables, $this->getSelectedEnvironment()->getVariables());
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');

        $rows = [];

        foreach ($variables as $variable) {
            if ($variable instanceof EnvironmentLevelVariable) {
                $level = 'environment (' . $variable->environment . ')';
            } elseif ($variable instanceof ProjectLevelVariable) {
                $level = 'project';
            } else {
                $level = 'unknown';
            }
            $row = [];
            $row[] = $variable->name;
            $row[] = $level;
            $row[] = $variable->value;
            $rows[] = $row;
        }

        $table->render($rows, ['Name', 'Level', 'Value']);

        return 0;
    }
}
