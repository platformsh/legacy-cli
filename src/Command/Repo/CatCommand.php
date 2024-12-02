<?php

namespace Platformsh\Cli\Command\Repo;

use Platformsh\Cli\Service\Config;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'repo:cat', description: 'Read a file in the project repository')]
class CatCommand extends RepoCommandBase
{
    public function __construct(private readonly Config $config)
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
        $this->addProjectOption();
        $this->addEnvironmentOption();
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
        $this->validateInput($input, false, true);

        return $this->cat($this->getSelectedEnvironment(), $input, $output);
    }
}
