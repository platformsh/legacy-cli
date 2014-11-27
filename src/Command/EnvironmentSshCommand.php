<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Environment;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class EnvironmentSshCommand extends PlatformCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('environment:ssh')
            ->setAliases(array('ssh'))
            ->addArgument('remote-cmd', InputArgument::IS_ARRAY, 'A command to run on the environment.')
            ->addOption('project', null, InputOption::VALUE_OPTIONAL, 'The project ID')
            ->addOption('environment', null, InputOption::VALUE_OPTIONAL, 'The environment ID')
            ->addOption('identity-file', 'i', InputOption::VALUE_OPTIONAL, 'An identity file to pass to SSH.')
            ->addOption('pipe', NULL, InputOption::VALUE_NONE, "Output the SSH URL only.")
            ->setDescription('SSH to the current environment.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $environment = new Environment($this->environment);
        $sshUrl = $environment->getSshUrl();

        if ($input->getOption('pipe') || !$this->isTerminal($output)) {
            $output->write($sshUrl);
            return 0;
        }

        $args = $input->getArgument('remote-cmd');

        $identityOption = '';
        if ($identityFile = $input->getOption('identity-file')) {
            $identityOption = '-i ' . escapeshellarg($identityFile);
        }

        if ($args) {
            $remoteCommand = implode(' ', array_map('escapeshellarg', $args));
            $command = "ssh -qt $identityOption $sshUrl $remoteCommand";
        }
        else {
            $command = "ssh " . escapeshellarg($sshUrl);
        }

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $output->writeln("Running command: $command");
        }

        passthru($command, $returnVar);
        return $returnVar;
    }

}
