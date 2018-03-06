<?php
namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Console\AdaptiveTableCell;
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
        $this->validateInput($input, !$input->getOption('environment'));

        $variables = $this->getSelectedProject()->getVariables();

        if ($this->hasSelectedEnvironment()) {
            $variables = array_merge($variables, $this->getSelectedEnvironment()->getVariables());
        } elseif (!$variables) {
            $this->stdErr->writeln('No variables found.');
            $this->stdErr->writeln('Use the --environment option to show environment-level variables.');

            return 1;
        }

        if (!$variables) {
            $this->stdErr->writeln('No variables found.');

            return 1;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');

        $rows = [];

        foreach ($variables as $variable) {
            $row = [];
            $row[] = $variable->name;
            $row[] = new AdaptiveTableCell($this->getVariableLevel($variable), ['wrap' => false]);
            $row[] = wordwrap($variable->value, 40, "\n", true);
            $rows[] = $row;
        }

        $table->render($rows, ['Name', 'Level', 'Value']);

        return 0;
    }
}
