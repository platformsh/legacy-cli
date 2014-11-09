<?php

namespace CommerceGuys\Platform\Cli\Command;

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

    public function isEnabled() {
        // Hide the command in the list.
        global $argv;
        return !isset($argv[1]) || $argv[1] != 'list';
    }

    public function isLocal()
    {
      return TRUE;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("Welcome to Platform.sh!\n");

        $application = $this->getApplication();

        if ($currentProject = $this->getCurrentProject()) {
            // The project is known. Show the environments.
            $projectName = $currentProject['name'];
            $output->writeln("Your project is <info>$projectName</info>.\n");
            $application->find('environments')->run($input, $output);
            $output->writeln("\nYou can list other projects by running <info>platform projects</info>.\n");
            $output->writeln("Manage your domains by running <info>platform domains</info>.");
        } else {
            // The project is not known. Show all projects.
            $application->find('projects')->run($input, $output);
        }

        $output->writeln("Manage your SSH keys by running <info>platform ssh-keys</info>.");

        $output->writeln("List all commands and their options by running <info>platform help</info>.\n");

        $output->writeln("Type <info>platform list</info> to see all available commands.");
    }

}
