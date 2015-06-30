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
            $this->stdErr->writeln("<error>Directory not found: $directory</error>");

            return 1;
        }

        if (!is_dir($realPath . '/.git')) {
            /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $questionHelper */
            $questionHelper = $this->getHelper('question');
            $question = "The directory is not a Git repository: <comment>$realPath</comment>\nInitialize a Git repository?";
            if ($questionHelper->confirm($question, $input, $this->stdErr)) {
                /** @var \Platformsh\Cli\Helper\GitHelper $gitHelper */
                $gitHelper = $this->getHelper('git');
                $gitHelper->ensureInstalled();
                $gitHelper->init($realPath, true);
            }
        }

        $gitUrl = null;
        $projectId = $input->getOption('project') ?: null;
        if ($projectId !== null) {
            $project = $this->getProject($projectId, $input->getOption('host'));
            if (!$project) {
                $this->stdErr->writeln("Project not found: <error>$projectId</error>");
                return 1;
            }
            $gitUrl = $project->getGitUrl();
            $gitUrl = $this->customizeHost($gitUrl);
        }

        $inside = strpos(getcwd(), $realPath) === 0;

        $local = new LocalProject();
        $projectRoot = $local->initialize($realPath, $projectId, $gitUrl);

        // Fetch environments to start caching, and to create Drush aliases,
        // etc.
        $this->setProjectRoot($projectRoot);
        $this->getEnvironments($this->getCurrentProject(), true);

        $this->stdErr->writeln("Project initialized in directory: <info>$projectRoot</info>");

        if ($inside) {
            $this->stdErr->writeln("<comment>Type 'cd .' to refresh your shell</comment>");
        }

        return 0;
    }

}
