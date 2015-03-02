<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Helper\ArgvHelper;
use Symfony\Component\Console\Input\ArgvInput;
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
            ->addArgument('cmd', InputArgument::OPTIONAL, 'A command to run on the environment.')
            ->addOption('pipe', NULL, InputOption::VALUE_NONE, "Output the SSH URL only.")
            ->setDescription('SSH to the current environment');
        $this->addProjectOption()->addEnvironmentOption();
        $this->ignoreValidationErrors();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $sshUrl = $this->getSelectedEnvironment()->getSshUrl();

        $command = $input->getArgument('cmd');
        if ($input instanceof ArgvInput) {
            $helper = new ArgvHelper();
            $command = $helper->getPassedCommand($this, $input);
        }

        if (!$command) {
            if ($input->getOption('pipe') || !$this->isTerminal($output)) {
                $output->write($sshUrl);
                return 0;
            }
            $command = "ssh " . escapeshellarg($sshUrl);
            passthru($command, $returnVar);
            return $returnVar;
        }

        $sshOptions = 'qt';

        // Pass through the verbosity options to SSH.
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $sshOptions .= 'vv';
        }
        elseif ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $sshOptions .= 'v';
        }

        $command = "ssh -$sshOptions " . escapeshellarg($sshUrl) . ' ' . escapeshellarg($command);

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $output->writeln("Running command: <info>$command</info>");
        }

        passthru($command, $returnVar);
        return $returnVar;
    }

}
