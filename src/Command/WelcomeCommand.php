<?php

namespace CommerceGuys\Platform\Cli\Command;

use Guzzle\Http\ClientInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Dumper;

class WelcomeCommand extends PlatformCommand
{

    protected $projectListCommand;

    public function __construct($projectListCommand)
    {
        $this->projectListCommand = $projectListCommand;
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
        $output->writeln("\nWelcome to Commerce Platform! \n");
        $this->projectListCommand->execute($input, $output);

        $output->writeln("You can also manage your SSH keys by running <info>platform ssh-keys</info>.\n");
    }

}
