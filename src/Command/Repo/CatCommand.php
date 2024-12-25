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
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'repo:cat', description: 'Read a file in the project repository')]
class CatCommand extends RepoCommandBase
{
    public function __construct(private readonly Config $config, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'The path to the file')
            ->addCommitOption();
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->addExample(
            'Read the services configuration file',
            $this->config->getStr('service.project_config_dir') . '/services.yaml',
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(selectDefaultEnv: true));

        try {
            return $this->cat($input->getArgument('path'), $selection->getEnvironment(), $input, $output);
        } catch (GitObjectTypeException $e) {
            $this->stdErr->writeln(sprintf(
                '%s: <error>%s</error>',
                $e->getMessage(),
                $e->getPath(),
            ));
            $this->stdErr->writeln(sprintf('To list directory contents, run: <comment>%s repo:ls [path]</comment>', $this->config->getStr('application.executable')));
            return 3;
        }
    }
}
