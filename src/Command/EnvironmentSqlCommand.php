<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentSqlCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:sql')
            ->setAliases(array('sql'))
            ->setDescription('Run SQL on the remote database')
            ->addArgument('query', InputArgument::OPTIONAL, 'An SQL statement to execute');
        $this->addProjectOption()->addEnvironmentOption()->addAppOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $sshOptions = '';

        $environment = $this->getSelectedEnvironment();
        $sshUrl = $environment->getSshUrl($input->getOption('app'));

        $sqlCommand = "mysql --database=main --no-auto-rehash --host=database.internal --user= --password=";

        $query = $input->getArgument('query');
        if ($query) {
            $sqlCommand .= ' --execute ' . escapeshellarg($query) . ' 2>&1';
        }
        else {
            $sshOptions .= ' -qt';
        }

        $command = 'ssh' . $sshOptions . ' ' . escapeshellarg($sshUrl)
          . ' ' . escapeshellarg($sqlCommand);

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $output->writeln("Running command: <info>$command</info>");
        }

        passthru($command, $return_var);
        return $return_var;
    }
}
