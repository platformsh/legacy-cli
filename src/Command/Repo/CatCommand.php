<?php

namespace Platformsh\Cli\Command\Repo;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CatCommand extends RepoCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('repo:cat') // 🐱
            ->setDescription('Read a file in the project repository')
            ->addArgument('path', InputArgument::REQUIRED, 'The path to the file')
            ->addCommitOption();
        $this->addProjectOption();
        $this->addEnvironmentOption();
        $this->addExample(
            'Read the services configuration file',
            $this->config()->get('service.project_config_dir') . '/services.yaml'
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, false, true);

        return $this->cat($this->getSelectedEnvironment(), $input, $output);
    }
}
