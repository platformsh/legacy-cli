<?php

namespace Platformsh\Cli\Command\Repo;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Exception\GitObjectTypeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CatCommand extends CommandBase
{
    protected static $defaultName = 'repo:cat'; // 🐱

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setDescription('Read a file in the project repository')
            ->addArgument('path', InputArgument::REQUIRED, 'The path to the file');
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
        $this->validateInput($input);
        $path = $input->getArgument('path');
        try {
            $content = $this->api()->readFile($path, $this->getSelectedEnvironment());
        } catch (GitObjectTypeException $e) {
            $this->stdErr->writeln(sprintf(
                '%s: <error>%s</error>',
                $e->getMessage(),
                $e->getPath()
            ));
            $this->stdErr->writeln(sprintf('To list directory contents, run: <comment>%s repo:ls [path]</comment>', $this->config()->get('application.executable')));

            return 3;
        }
        if ($content === false) {
            $this->stdErr->writeln(sprintf('File not found: <error>%s</error>', $path));

            return 2;
        }

        $output->write($content, false, OutputInterface::OUTPUT_RAW);

        return 0;
    }
}
