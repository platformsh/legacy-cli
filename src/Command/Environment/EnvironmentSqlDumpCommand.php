<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Util\RelationshipsUtil;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentSqlDumpCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('environment:sql-dump')
            ->setAliases(array('sql-dump'))
            ->setDescription('Create a local dump of the remote database')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'A filename where the dump should be saved. Defaults to "environment-dump.sql" in the project root')
            ->addOption('stdout', null, InputOption::VALUE_NONE, 'Output to STDOUT instead of a file');
        $this->addProjectOption()->addEnvironmentOption()->addAppOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $environment = $this->getSelectedEnvironment();
        $appName = $this->selectApp($input);
        $sshUrl = $environment->getSshUrl($appName);

        if (!$input->getOption('stdout')) {
            if ($input->getOption('file')) {
                $dumpFile = $input->getOption('file');
                /** @var \Platformsh\Cli\Helper\FilesystemHelper $fsHelper */
                $fsHelper = $this->getHelper('fs');
                $dumpFile = $fsHelper->makePathAbsolute($dumpFile);
                if (is_dir($dumpFile)) {
                    $dumpFile .= "/{$environment->id}-dump.sql";
                }
            }
            else {
                if (!$projectRoot = $this->getProjectRoot()) {
                    throw new RootNotFoundException(
                      'Project root not found. Specify --file or go to a project directory.'
                    );
                }
                $dumpFile = $projectRoot . '/' . $environment->id;
                if ($appName) {
                    $dumpFile .= '--' . $appName;
                }
                $dumpFile .= '-dump.sql';
            }
        }

        if (isset($dumpFile)) {
            if (file_exists($dumpFile)) {
                /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $questionHelper */
                $questionHelper = $this->getHelper('question');
                if (!$questionHelper->confirm("File exists: <comment>$dumpFile</comment>. Overwrite?", $input, $this->stdErr, false)) {
                    return 1;
                }
            }
            $this->stdErr->writeln("Creating SQL dump file: <info>$dumpFile</info>");
        }

        $util = new RelationshipsUtil($this->stdErr);
        $database = $util->chooseDatabase($sshUrl, $input);
        if (empty($database)) {
            return 1;
        }

        switch ($database['scheme']) {
            case 'pgsql':
                $dumpCommand = "pg_dump --clean"
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
          . ' ' . escapeshellarg($dumpCommand);
        if (isset($dumpFile)) {
            $command .= ' > ' . escapeshellarg($dumpFile);
        }

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->stdErr->writeln("Running command: <info>$command</info>");
        }

        passthru($command, $return_var);
        return $return_var;
    }
}
