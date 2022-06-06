<?php

namespace Platformsh\Cli\Command\Repo;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\GitDataApi;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\SubCommandRunner;
use Platformsh\Client\Model\Git\Tree;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ReadCommand extends CommandBase
{
    protected static $defaultName = 'repo:read|read';
    protected static $defaultDescription = 'Read a directory or file in the project repository';

    private $gitDataApi;
    private $selector;
    private $subCommandRunner;

    public function __construct(
        GitDataApi $gitDataApi,
        Selector $selector,
        SubCommandRunner $subCommandRunner
    ) {
        $this->gitDataApi = $gitDataApi;
        $this->selector = $selector;
        $this->subCommandRunner = $subCommandRunner;
        parent::__construct();
    }


    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('path', InputArgument::OPTIONAL, 'The path to the directory or file')
            ->addOption('commit', 'c', InputOption::VALUE_REQUIRED, 'The commit SHA. ' . GitDataApi::COMMIT_SYNTAX_HELP);
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input, false, true);
        $environment = $selection->getEnvironment();

        $path = $input->getArgument('path');
        $object = $this->gitDataApi->getObject($path, $environment, $input->getOption('commit'));
        if ($object === false) {
            $this->stdErr->writeln(sprintf('File or directory not found: <error>%s</error>', $path));

            return 2;
        }
        if ($object instanceof Tree) {
            $cmd = 'repo:ls';
        } else {
            $cmd = 'repo:cat';
        }
        return $this->subCommandRunner->run($cmd, \array_filter([
            'path' => $path,
            '--commit' => $input->getOption('commit'),
            '--project' => $selection->getProject()->id,
            '--environment' => $selection->getEnvironment()->id,
        ]));
    }
}
