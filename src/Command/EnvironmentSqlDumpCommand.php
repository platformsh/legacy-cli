<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Util\RelationshipsUtil;
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
            ->setDescription('Create a local dump of the remote database')
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

        $sshUrl = $this->getSelectedEnvironment()
                       ->getSshUrl($input->getOption('app'));

        $util = new RelationshipsUtil($output);
        $database = $util->chooseDatabase($sshUrl, $input);
        if (empty($database)) {
            return 1;
        }

        switch ($database['scheme']) {
            case 'pgsql':
                $dumpCommand = "pg_dump postgresql://{$database['username']}:{$database['password']}@{$database['host']}/{$database['path']}";
                break;

            default:
                $dumpCommand = "mysqldump --no-autocommit --single-transaction"
                  . " --opt -Q {$database['path']}"
                  . " --host={$database['host']} --port={$database['port']}"
                  . " --user={$database['username']} --password={$database['password']}";
                break;
        }

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
