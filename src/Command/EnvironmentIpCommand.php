<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class EnvironmentIpCommand extends EnvironmentCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('environment:ip')
            ->addOption('project', null, InputOption::VALUE_OPTIONAL, 'The project id')
            ->addOption('environment', null, InputOption::VALUE_OPTIONAL, 'The environment id')
            ->setDescription('Get the external IP address of the current environment.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return;
        }

        $sshUrlString = $this->getSshUrl();
        passthru('ssh ' . $sshUrlString . ' "curl --silent https://icanhazip.com/"');
    }

}
