<?php
namespace Platformsh\Cli\Command\Project\Variable;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @deprecated Use variable:delete instead
 */
class ProjectVariableDeleteCommand extends CommandBase
{
    protected $hiddenInList = true;

    protected static $defaultName = 'project:variable:delete';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The variable name')
            ->setDescription('Delete a variable from a project');
        $this->addProjectOption()
             ->addWaitOptions();
        $this->addExample('Delete the variable "example"', 'example');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        return $this->runOtherCommand('variable:delete', [
                'name' => $input->getArgument('name'),
                '--level' => 'project',
                '--project' => $this->getSelectedProject()->id,
            ] + array_filter([
                '--wait' => $input->getOption('wait'),
                '--no-wait' => $input->getOption('no-wait'),
            ]));
    }
}
