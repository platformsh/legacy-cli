<?php

namespace Platformsh\Cli\Command\Repo;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Selector\Selector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'repo:ls', description: 'List files in the project repository')]
class LsCommand extends RepoCommandBase
{

    public function __construct(private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'The path to a subdirectory')
            ->addOption('directories', 'd', InputOption::VALUE_NONE, 'Show directories only')
            ->addOption('files', 'f', InputOption::VALUE_NONE, 'Show files only')
            ->addOption('git-style', null, InputOption::VALUE_NONE, 'Style output similar to "git ls-tree"')
            ->addCommitOption();
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(selectDefaultEnv: true));

        return $this->ls($selection->getEnvironment(), $input, $output);
    }
}
