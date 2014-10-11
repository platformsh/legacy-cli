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
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the raw IP address, suitable for piping to another command.')
            ->setDescription('Get the public IP address of an environment.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $sshUrlString = $this->getSshUrl();

        $ip = trim(shell_exec('ssh ' . $sshUrlString . ' "curl --silent --max-time 30 https://icanhazip.com/"'));
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $output->writeln('<error>The IP address could not be found.</error>');
            return 1;
        }

        if ($input->getOption('pipe')) {
            $output->write($ip);
        }
        else {
            $output->writeln("Public IP address: <info>$ip</info>");
        }

        return 0;
    }

}
