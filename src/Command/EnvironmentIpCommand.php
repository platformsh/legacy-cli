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
            ->addOption('provider', null, InputOption::VALUE_OPTIONAL, 'The IP address provider URL.', 'https://icanhazip.com/')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the raw IP address, suitable for piping to another command.')
            ->setDescription('Get the public IP address of an environment.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $provider = $input->getOption('provider');
        if (!filter_var($provider, FILTER_VALIDATE_URL, FILTER_FLAG_HOST_REQUIRED & FILTER_FLAG_SCHEME_REQUIRED)) {
            $output->writeln('<error>Invalid provider URL.</error>');
            return 1;
        }

        $sshUrlString = $this->getSshUrl();

        $curlCommand = "curl --silent --show-error --max-time 30 $provider";

        $ip = trim(shell_exec("ssh $sshUrlString " . escapeshellarg($curlCommand)));
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
