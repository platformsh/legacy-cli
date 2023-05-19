<?php

namespace Platformsh\Cli\Command\Repo;

use Platformsh\Cli\Service\GitDataApi;
use Platformsh\Client\Model\Git\Tree;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ReadCommand extends RepoCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('repo:read')
            ->setAliases(['read'])
            ->setDescription('Read a directory or file in the project repository')
            ->addArgument('path', InputArgument::OPTIONAL, 'The path to the directory or file')
            ->addCommitOption();
        $this->addProjectOption();
        $this->addEnvironmentOption();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, false, true);
        $environment = $this->getSelectedEnvironment();

        $path = $input->getArgument('path');
        /** @var GitDataApi $gitData */
        $gitData = $this->getService('git_data_api');
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
