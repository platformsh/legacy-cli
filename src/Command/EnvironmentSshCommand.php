<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class EnvironmentSshCommand extends EnvironmentCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('environment:ssh')
            ->setAliases(array('ssh'))
            ->addOption('project', null, InputOption::VALUE_OPTIONAL, 'The project id')
            ->addOption('environment', null, InputOption::VALUE_OPTIONAL, 'The environment id')
            ->addOption('echo', NULL, InputOption::VALUE_NONE, "Print the connection string to the console.")
            ->setDescription('SSH to the current environment.');
        // $this->ignoreValidationErrors(); @todo: Pass extra stuff to ssh? -i?
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return;
        }

        $sshUrlString = $this->getSshUrl();

        $command = 'ssh ' . $sshUrlString;
        $execute = !$input->getOption('echo');
        if ($execute) {
            passthru($command);
            return;
        }
        else {
            $output->writeln("The SSH url for the current environment is: <info>" . $command . "</info>");
            return;
        }
    }

}
