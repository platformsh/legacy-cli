<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Local\LocalProject;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LocalInitCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('local:init')
          ->setAliases(array('init'))
          ->addArgument('directory', InputArgument::OPTIONAL, 'The path to the repository.')
          ->setDescription('Create a local project file structure from a Git repository');
        $this->addProjectOption();
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

        $projectId = null;
        $gitUrl = null;
        if ($input->getOption('project')) {
            $project = $this->selectProject($input->getOption('project'));
            $projectId = $project->id;
            $gitUrl = $project->getGitUrl();
        }

        $inside = strpos(getcwd(), $realPath) === 0;

        $local = new LocalProject();
        $projectRoot = $local->initialize($directory, $projectId, $gitUrl);

        // Fetch environments to start caching, and to create Drush aliases,
        // etc.
        $this->setProjectRoot($projectRoot);
        $this->getEnvironments($this->getCurrentProject(), true);

        $output->writeln("Project initialized in directory: <info>$projectRoot</info>");

        if ($inside) {
            $output->writeln("<comment>Type 'cd .' to refresh your shell</comment>");
        }

        return 0;
    }

}
