<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Project\Variable;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\SubCommandRunner;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @deprecated Use variable:get and variable:list instead
 */
#[AsCommand(name: 'project:variable:get', description: 'View variable(s) for a project', aliases: ['project-variables', 'pvget'])]
class ProjectVariableGetCommand extends CommandBase
{
    protected bool $hiddenInList = true;
    protected string $stability = 'deprecated';
    public function __construct(private readonly Selector $selector, private readonly SubCommandRunner $subCommandRunner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'The name of the variable')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the full variable value only (a "name" must be specified)');
        $this->setHelp(
            'This command is deprecated and will be removed in a future version.'
            . "\nInstead, use <info>variable:list</info> and <info>variable:get</info>",
        );
        Table::configureInput($this->getDefinition());
        $this->selector->addProjectOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->setHiddenAliases(['project:variable:list']);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);

        return $this->subCommandRunner->run('variable:get', [
            'name' => $input->getArgument('name'),
            '--level' => 'project',
            '--project' => $selection->getProject()->id,
        ] + array_filter([
            '--format' => $input->getOption('format'),
            '--pipe' => $input->getOption('pipe'),
        ]));
    }
}
