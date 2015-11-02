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

        if ($currentProject = $this->getCurrentProject()) {
            // The project is known. Show the environments.
            $projectName = $currentProject->title;
            $projectURI = $currentProject->getLink('#ui');
            $projectId = $currentProject->id;
            $this->stdErr->writeln("Project Name: <info>$projectName</info>");
            $this->stdErr->writeln("Project ID: <info>$projectId</info>");
            $this->stdErr->writeln("Project Dashboard: <info>$projectURI</info>\n");
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
