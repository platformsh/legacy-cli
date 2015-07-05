<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Helper\ArgvHelper;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

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
          ->addArgument('cmd', InputArgument::OPTIONAL, 'A command to run on the environment')
          ->addOption('pipe', null, InputOption::VALUE_NONE, "Output the SSH URL only")
          ->addOption('no-wait', null, InputOption::VALUE_NONE, "Do not wait for the environment to become active first")
          ->setDescription('SSH to the current environment');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addAppOption();
        $this->ignoreValidationErrors();
        $this->addExample('Read recent messages in the deploy log', "'tail /var/log/deploy.log'");
        $this->addExample('Open a shell over SSH');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $environment = $this->getSelectedEnvironment();

        $this->waitUntilEnvironmentActive($environment, $this->getSelectedProject(), $input);

        $sshUrl = $environment->getSshUrl($input->getOption('app'));

        if ($input->getOption('pipe') || !$this->isTerminal($output)) {
            $output->write($sshUrl);

            return 0;
        }

        $remoteCommand = $input->getArgument('cmd');
        if ($input instanceof ArgvInput) {
            $helper = new ArgvHelper();
            $remoteCommand = $helper->getPassedCommand($this, $input);
        }

        $sshOptions = 't';

        // Pass through the verbosity options to SSH.
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $sshOptions .= 'vv';
        } elseif ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $sshOptions .= 'v';
        } elseif ($output->getVerbosity() <= OutputInterface::VERBOSITY_NORMAL) {
            $sshOptions .= 'q';
        }

        $command = "ssh -$sshOptions " . escapeshellarg($sshUrl);
        if ($remoteCommand) {
            $command .= ' ' . escapeshellarg($remoteCommand);
        }

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->stdErr->writeln("Running command: <info>$command</info>");
        }

        passthru($command, $returnVar);

        return $returnVar;
    }

}
