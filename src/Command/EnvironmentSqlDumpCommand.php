<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Exception\RootNotFoundException;
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
        $this->validateInput($input);

        $dumpFile = $input->getOption('file');
        if ($dumpFile) {
            /** @var \Platformsh\Cli\Helper\FilesystemHelper $fsHelper */
            $fsHelper = $this->getHelper('fs');
            $dumpFile = $fsHelper->makePathAbsolute($dumpFile);
            if (is_dir($dumpFile)) {
                $dumpFile .= '/' . 'dump.sql';
            }
        }
        elseif (!$projectRoot = $this->getProjectRoot()) {
            throw new RootNotFoundException(
              'Project root not found. Specify --file or go to a project directory.'
            );
        }
        else {
            $dumpFile = $projectRoot . '/dump.sql';
        }

        if (file_exists($dumpFile)) {
            /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $questionHelper */
            $questionHelper = $this->getHelper('question');
            if (!$questionHelper->confirm("File exists: <comment>$dumpFile</comment>. Overwrite?", $input, $this->stdErr, false)) {
                return 1;
            }
        }

        $this->stdErr->writeln("Creating SQL dump file: <info>$dumpFile</info>");

        $sshUrl = $this->getSelectedEnvironment()
                       ->getSshUrl($input->getOption('app'));
        $sshUrl = $this->customizeHost($sshUrl);

        $util = new RelationshipsUtil($this->stdErr);
        $database = $util->chooseDatabase($sshUrl, $input);
        if (empty($database)) {
            return 1;
        }

        switch ($database['scheme']) {
            case 'pgsql':
                $dumpCommand = "pg_dump --clean --single-transaction"
                  . " postgresql://{$database['username']}:{$database['password']}@{$database['host']}/{$database['path']}";
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
            $this->stdErr->writeln("Running command: <info>$command</info>");
        }

        passthru($command, $return_var);
        return $return_var;
    }
}
