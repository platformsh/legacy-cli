<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentSqlDumpCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:sql-dump')
            ->setAliases(array('sql-dump'))
            ->setDescription('Create a dump of the remote database')
            ->addOption('file', 'f', InputOption::VALUE_OPTIONAL, 'A filename where the dump should be saved. Defaults to "dump.sql" in the project root');
        $this->addProjectOption()->addEnvironmentOption()->addAppOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $projectRoot = $this->getProjectRoot();
        $dumpFile = $input->getOption('file');
        if ($dumpFile !== null) {
            if (file_exists($dumpFile)) {
                $output->writeln("File exists: <error>$dumpFile</error>");
                return 1;
            }

            $dir = dirname($dumpFile);

            if (!is_dir($dir)) {
                $output->writeln("Directory not found: <error>$dir</error>");
                return 1;
            }
            elseif (!is_writable($dir)) {
                $output->writeln("Directory not writable: <error>$dir</error>");
                return 1;
            }

            $dumpFile = realpath($dir) . '/' . basename($dumpFile);
        }
        else {
            $dumpFile = $projectRoot . '/dump.sql';
            if (file_exists($dumpFile)) {
                $output->writeln("File exists: <error>$dumpFile</error>");
                return 1;
            }
        }

        $output->writeln("Creating SQL dump file: <info>$dumpFile</info>");

        $environment = $this->getSelectedEnvironment();
        $sshUrl = $environment->getSshUrl($input->getOption('app'));

        $dumpCommand = "mysqldump --no-autocommit --single-transaction --opt -Q main --host=database.internal --user= --password=";

        set_time_limit(0);

        $command = 'ssh ' . escapeshellarg($sshUrl)
          . ' ' . escapeshellarg($dumpCommand)
          . ' > ' . escapeshellarg($dumpFile);

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $output->writeln("Running command: <info>$command</info>");
        }

        passthru($command, $return_var);
        return $return_var;
    }
}
