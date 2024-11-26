<?php
namespace Platformsh\Cli\Command\Project\Variable;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @deprecated Use variable:delete instead
 */
#[AsCommand(name: 'project:variable:delete', description: 'Delete a variable from a project')]
class ProjectVariableDeleteCommand extends CommandBase
{
    protected $hiddenInList = true;
    protected $stability = 'deprecated';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The variable name');
        $this->setHelp(
            'This command is deprecated and will be removed in a future version.'
            . "\nInstead, use: <info>variable:delete --level project [variable]</info>"
        );
        $this->addProjectOption()
             ->addWaitOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
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
