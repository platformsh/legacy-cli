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
        Table::configureInput($this->getDefinition());
        $this->addProjectOption();
        $this->addExample('View the variable "example"', 'example');
        $this->setHiddenAliases(['project:variable:list']);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        if ($name = $input->getArgument('name')) {
            $variable = $this->getSelectedProject()
                             ->getVariable($name);
            if (!$variable) {
                $this->stdErr->writeln("Variable not found: <error>$name</error>");

                return 1;
            }

            if ($input->getOption('pipe')) {
                $output->writeln($variable->value);
            } else {
                $output->writeln(sprintf('<info>%s</info>: %s', $variable->name, $variable->value));
            }

            return 0;
        }

        return $this->runOtherCommand('variable:list', [
            '--level' => 'project',
            '--project' => $this->getSelectedProject()->id,
        ]);
    }
}
