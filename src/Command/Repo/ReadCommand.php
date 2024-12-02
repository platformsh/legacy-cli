<?php

namespace Platformsh\Cli\Command\Repo;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\GitDataApi;
use Platformsh\Client\Model\Git\Tree;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'repo:read', description: 'Read a directory or file in the project repository', aliases: ['read'])]
class ReadCommand extends RepoCommandBase
{
    public function __construct(private readonly GitDataApi $gitDataApi, private readonly Selector $selector)
    {
        parent::__construct();
    }
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'The path to the directory or file')
            ->addCommitOption();
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(envRequired: !false, selectDefaultEnv: true));
        $environment = $selection->getEnvironment();

        $path = $input->getArgument('path');
        $gitData = $this->gitDataApi;
        $object = $gitData->getObject($path, $environment, $input->getOption('commit'));
        if ($object === false) {
            $this->stdErr->writeln(sprintf('File or directory not found: <error>%s</error>', $path));

            return 2;
        }

        return $object instanceof Tree
            ? $this->ls($environment, $input, $output)
            : $this->cat($environment, $input, $output);
    }
}
