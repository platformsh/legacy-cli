<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WelcomeCommand extends PlatformCommand
{

    protected $projectListCommand;
    protected $environmentListCommand;

    public function __construct($projectListCommand, $environmentListCommand)
    {
        $this->projectListCommand = $projectListCommand;
        $this->environmentListCommand = $environmentListCommand;
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('welcome')
            ->setDescription('Welcome to platform');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("\nWelcome to Commerce Platform!");

        if ($currentProject = $this->getCurrentProject()) {
            // The project is known. Show the environments.
            $projectName = $currentProject['name'];
            $output->write("\nYour project is <info>$projectName</info>.");
            $this->environmentListCommand->execute($input, $output);
            $output->writeln("You can list other projects by running <info>platform projects</info>.");
        } else {
            // The project is not known. Show all projects.
            $this->projectListCommand->execute($input, $output);
        }

        $output->writeln("Manage your SSH keys by running <info>platform ssh-keys</info>.");
        $output->writeln("Manage your domains by running <info>platform domain</info>.\n");
        
        $output->writeln("List all commands and their options by running <info>platform help</info>.\n");

        $output->writeln("Type <info>platform list</info> to see all available commands.\n");
    }

}
