<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WelcomeCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('welcome')
          ->setDescription('Welcome to Platform.sh');
        $this->setHiddenInList();
    }

    public function isLocal()
    {
        return true;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->stdErr->writeln("Welcome to Platform.sh!\n");

        // Ensure the user is logged in in this parent command, because the
        // delegated commands below will not have interactive input.
        $this->getClient();

        if ($project = $this->getCurrentProject()) {
            // The project is known. Show the environments.
            $projectUri = $project->getLink('#ui');
            $this->stdErr->writeln("Project title: <info>{$project->title}</info>");
            $this->stdErr->writeln("Project ID: <info>{$project->id}</info>");
            $this->stdErr->writeln("Project dashboard: <info>$projectUri</info>\n");
            $this->runOtherCommand('environments', array('--refresh' => 0));
            $this->stdErr->writeln("\nYou can list other projects by running <info>platform projects</info>\n");
            $this->stdErr->writeln("Manage your domains by running <info>platform domains</info>");
        } else {
            // The project is not known. Show all projects.
            $this->runOtherCommand('projects', array('--refresh' => 0));
        }

        $this->stdErr->writeln("Manage your SSH keys by running <info>platform ssh-keys</info>\n");

        $this->stdErr->writeln("Type <info>platform list</info> to see all available commands.");
    }

}
