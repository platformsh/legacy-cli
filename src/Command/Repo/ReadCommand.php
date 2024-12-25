<?php

declare(strict_types=1);

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

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'The path to the directory or file')
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
        $environment = $selection->getEnvironment();

        $path = $input->getArgument('path') ?: '/';
        $object = $this->gitDataApi->getObject($path, $environment, $input->getOption('commit'));
        if ($object === false) {
            $this->stdErr->writeln(sprintf('File or directory not found: <error>%s</error>', $path));

            return 2;
        }

        return $object instanceof Tree
            ? $this->ls($path, $environment, $input, $output)
            : $this->cat($path, $environment, $input, $output);
    }
}
