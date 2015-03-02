<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WelcomeCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('welcome')
          ->setDescription('Welcome to Platform.sh');
    }

    public function isEnabled()
    {
        // Hide the command in the list.
        global $argv;

        return !isset($argv[1]) || $argv[1] != 'list';
    }

    public function isLocal()
    {
        return true;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("Welcome to Platform.sh!\n");

        if (!$this->isLoggedIn()) {
            $this->login();
        }

        $application = $this->getApplication();

        if ($currentProject = $this->getCurrentProject()) {
            // The project is known. Show the environments.
            $projectName = $currentProject['title'];
            $projectURI = $currentProject->getLink('#ui');
            $projectId = $currentProject['id'];
            $output->writeln("Project Name: <info>$projectName</info>");
            $output->writeln("Project ID: <info>$projectId</info>");
            $output->writeln("Project Dashboard: <info>$projectURI</info>\n");
            $envInput = new ArrayInput(
              array(
                'command' => 'environments',
                '--refresh' => 0,
              )
            );
            $application->find('environments')
                        ->run($envInput, $output);
            $output->writeln("\nYou can list other projects by running <info>platform projects</info>.\n");
            $output->writeln("Manage your domains by running <info>platform domains</info>.");
        } else {
            // The project is not known. Show all projects.
            $projectsInput = new ArrayInput(
              array(
                'command' => 'projects',
                '--refresh' => 0,
              )
            );
            $application->find('projects')
                        ->run($projectsInput, $output);
        }

        $output->writeln("Manage your SSH keys by running <info>platform ssh-keys</info>\n");

        $output->writeln("Type <info>platform list</info> to see all available commands.");
    }

}
