<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Local\LocalProject;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectInitCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('project:init')
          ->setAliases(array('init'))
          ->addArgument('directory', InputArgument::OPTIONAL, 'The path to the repository.')
          ->setDescription('Initialize from a plain Git repository');
    }

    public function isLocal()
    {
        return true;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $directory = $input->getArgument('directory') ?: getcwd();
        $realPath = realpath($directory);
        if (!$realPath) {
            $output->writeln("<error>Directory not found: $directory</error>");

            return 1;
        }

        $inside = strpos(getcwd(), $realPath) === 0;

        $local = new LocalProject();
        $projectRoot = $local->initialize($directory);

        $output->writeln("Project initialized in directory: <info>$projectRoot</info>");

        if ($inside) {
            $output->writeln("<comment>Type 'cd .' to refresh your shell</comment>");
        }

        return 0;
    }

}
