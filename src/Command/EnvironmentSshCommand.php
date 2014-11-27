<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Environment;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class EnvironmentSshCommand extends EnvironmentCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('environment:ssh')
            ->setAliases(array('ssh'))
            ->addOption('project', null, InputOption::VALUE_OPTIONAL, 'The project ID')
            ->addOption('environment', null, InputOption::VALUE_OPTIONAL, 'The environment ID')
            ->addOption('pipe', NULL, InputOption::VALUE_NONE, "Output the SSH URL only.")
            ->setDescription('SSH to the current environment.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return;
        }

        $environment = new Environment($this->environment);
        $sshUrlString = $environment->getSshUrl();

        if ($input->getOption('pipe') || !$this->isTerminal($output)) {
            $output->write($sshUrlString);
            return;
        }

        passthru("ssh $sshUrlString");
    }

}
