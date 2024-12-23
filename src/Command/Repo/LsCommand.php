<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Repo;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Config;
use Platformsh\Client\Exception\GitObjectTypeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'repo:ls', description: 'List files in the project repository')]
class LsCommand extends RepoCommandBase
{
    public function __construct(private readonly Config $config, private readonly Selector $selector)
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

        try {
            return $this->ls($input->getArgument('path') ?: '/', $selection->getEnvironment(), $input, $output);
        } catch (GitObjectTypeException $e) {
            $this->stdErr->writeln(sprintf(
                '%s: <error>%s</error>',
                $e->getMessage(),
                $e->getPath(),
            ));
            $this->stdErr->writeln(sprintf('To read a file, run: <comment>%s repo:cat [path]</comment>', $this->config->getStr('application.executable')));
            return 3;
        }
    }
}
