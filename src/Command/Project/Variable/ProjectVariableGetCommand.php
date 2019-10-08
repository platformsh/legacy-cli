<?php
namespace Platformsh\Cli\Command\Project\Variable;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @deprecated Use variable:get and variable:list instead
 */
class ProjectVariableGetCommand extends CommandBase
{
    protected $hiddenInList = true;
    protected $stability = 'deprecated';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('project:variable:get')
            ->setAliases(['project-variables', 'pvget'])
            ->addArgument('name', InputArgument::OPTIONAL, 'The name of the variable')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the full variable value only (a "name" must be specified)')
            ->setDescription('View variable(s) for a project');
        $this->setHelp(
            'This command is deprecated and will be removed in a future version.'
            . "\nInstead, use <info>variable:list</info> and <info>variable:get</info>"
        );
        Table::configureInput($this->getDefinition());
        $this->addProjectOption();
        $this->setHiddenAliases(['project:variable:list']);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        return $this->runOtherCommand('variable:get', [
            'name' => $input->getArgument('name'),
            '--level' => 'project',
            '--project' => $this->getSelectedProject()->id,
            ] + array_filter([
                '--format' => $input->getOption('format'),
                '--pipe' => $input->getOption('pipe'),
            ]));
    }
}
