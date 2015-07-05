<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Util\RelationshipsUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentSqlCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:sql')
            ->setAliases(array('sql'))
            ->setDescription('Run SQL on the remote database')
            ->addArgument('query', InputArgument::OPTIONAL, 'An SQL statement to execute')
            ->addOption('no-wait', null, InputOption::VALUE_NONE, "Do not wait for the environment to become active first");
        $this->addProjectOption()->addEnvironmentOption()->addAppOption();
        $this->addExample('Open an SQL console on the remote database');
        $this->addExample('View tables on the remote database', "'SHOW TABLES'");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $sshOptions = '';

        $selectedEnvironment = $this->getSelectedEnvironment();

        $this->waitUntilEnvironmentActive($selectedEnvironment, $this->getSelectedProject(), $input);

        $sshUrl = $selectedEnvironment->getSshUrl($input->getOption('app'));

        $util = new RelationshipsUtil($this->stdErr);
        $database = $util->chooseDatabase($sshUrl, $input);
        if (empty($database)) {
            return 1;
        }

        switch ($database['scheme']) {
            case 'pgsql':
                $sqlCommand = "psql postgresql://{$database['username']}:{$database['password']}@{$database['host']}/{$database['path']}";
                break;

            default:
                $sqlCommand = "mysql --no-auto-rehash --database={$database['path']}"
                  . " --host={$database['host']} --port={$database['port']}"
                  . " --user={$database['username']} --password={$database['password']}";
                break;
        }

        $query = $input->getArgument('query');
        if ($query) {
            $sqlCommand .= ' --execute ' . escapeshellarg($query) . ' 2>&1';
        }

        // Switch on pseudo-tty allocation when there is a local tty.
        if ($this->isTerminal($output)) {
            $sshOptions .= ' -t';
        }

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $sshOptions .= ' -vv';
        }
        elseif ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $sshOptions .= ' -v';
        }
        elseif ($output->getVerbosity() <= OutputInterface::VERBOSITY_VERBOSE) {
            $sshOptions .= ' -q';
        }

        $command = 'ssh' . $sshOptions . ' ' . escapeshellarg($sshUrl)
          . ' ' . escapeshellarg($sqlCommand);

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->stdErr->writeln("Running command: <info>$command</info>");
        }

        passthru($command, $return_var);
        return $return_var;
    }
}
