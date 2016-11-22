<?php
namespace Platformsh\Cli\Command\Project\Variable;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\ActivityUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectVariableDeleteCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('project:variable:delete')
            ->addArgument('name', InputArgument::REQUIRED, 'The variable name')
            ->setDescription('Delete a variable from a project');
        $this->addProjectOption()
             ->addNoWaitOption();
        $this->addExample('Delete the variable "example"', 'example');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $variableName = $input->getArgument('name');

        $variable = $this->getSelectedProject()
                         ->getVariable($variableName);
        if (!$variable) {
            $this->stdErr->writeln("Variable not found: <error>$variableName</error>");

            return 1;
        }

        if (!$variable->operationAvailable('delete')) {
            $this->stdErr->writeln("The variable <error>$variableName</error> cannot be deleted");

            return 1;
        }

        $projectId = $this->getSelectedProject()->id;
        $confirm = $this->getHelper('question')
                        ->confirm(sprintf("Delete the variable <info>%s</info> from the project <info>%s</info>?", $variableName, $projectId),
                            false
                        );

        if (!$confirm) {
            return 1;
        }

        $result = $variable->delete();

        $this->stdErr->writeln("Deleted variable <info>$variableName</info>");

        $success = true;
        if (!$result->countActivities()) {
            $this->rebuildWarning();
        }
        elseif (!$input->getOption('no-wait')) {
            $success = ActivityUtil::waitMultiple($result->getActivities(), $this->stdErr, $this->getSelectedProject());
        }

        return $success ? 0 : 1;
    }

}
