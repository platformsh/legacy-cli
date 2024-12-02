<?php

namespace Platformsh\Cli\Command\Repo;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Config;
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
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'The path to the file')
            ->addCommitOption();
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addExample(
            'Read the services configuration file',
            $this->config->get('service.project_config_dir') . '/services.yaml'
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new \Platformsh\Cli\Selector\SelectorConfig(envRequired: !false, selectDefaultEnv: true));

        return $this->cat($selection->getEnvironment(), $input, $output);
    }
}
