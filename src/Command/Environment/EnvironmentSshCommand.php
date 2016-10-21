<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentSshCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('environment:ssh')
            ->setAliases(['ssh'])
            ->addArgument('cmd', InputArgument::OPTIONAL, 'A command to run on the environment.')
            ->addOption('pipe', null, InputOption::VALUE_NONE, "Output the SSH URL only.")
            ->setDescription('SSH to the current environment');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addAppOption();
        $this->addExample('Read recent messages in the deploy log', "'tail /var/log/deploy.log'");
        $this->addExample('Open a shell over SSH');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $sshUrl = $this->getSelectedEnvironment()
                       ->getSshUrl($this->selectApp($input));

        if ($input->getOption('pipe')) {
            $output->write($sshUrl);

            return 0;
        }

        $remoteCommand = $input->getArgument('cmd');
        if (!$remoteCommand && $this->runningViaMulti) {
            throw new \InvalidArgumentException('The cmd argument is required when running via "multi"');
        }

        $sshOptions = 't';

        // Pass through the verbosity options to SSH.
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $sshOptions .= 'vv';
        } elseif ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $sshOptions .= 'v';
        } elseif ($output->getVerbosity() <= OutputInterface::VERBOSITY_QUIET) {
            $sshOptions .= 'q';
        }

        $command = "ssh -$sshOptions " . escapeshellarg($sshUrl);
        if ($remoteCommand) {
            $command .= ' ' . escapeshellarg($remoteCommand);
        }

        return $this->getHelper('shell')->executeSimple($command);
    }
}
