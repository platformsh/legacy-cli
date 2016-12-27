<?php
namespace Platformsh\Cli\Command\Db;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DbDumpCommand extends CommandBase
{

    protected function configure()
    {
        $this->setName('db:dump')
            ->setAliases(['sql-dump'])
            ->setDescription('Create a local dump of the remote database');
        $this->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'A filename where the dump should be saved. Defaults to "<project ID>--<environment ID>--dump.sql" in the project root')
            ->addOption('timestamp', 't', InputOption::VALUE_NONE, 'Add a timestamp to the dump filename')
            ->addOption('stdout', null, InputOption::VALUE_NONE, 'Output to STDOUT instead of a file');
        $this->addProjectOption()->addEnvironmentOption()->addAppOption();
        Relationships::configureInput($this->getDefinition());
        Ssh::configureInput($this->getDefinition());
        $this->setHiddenAliases(['environment:sql-dump']);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $project = $this->getSelectedProject();
        $environment = $this->getSelectedEnvironment();
        $appName = $this->selectApp($input);
        $sshUrl = $environment->getSshUrl($appName);
        $timestamp = $input->getOption('timestamp') ? str_replace('+', '', date('Ymd-His-O')) : null;

        if (!$input->getOption('stdout')) {
            if ($input->getOption('file')) {
                $dumpFile = rtrim($input->getOption('file'), '/');
                /** @var \Platformsh\Cli\Service\Filesystem $fs */
                $fs = $this->getService('fs');

                // Insert the timestamp into the filename.
                if ($timestamp) {
                    $basename = basename($dumpFile);
                    $prefix = substr($dumpFile, 0, - strlen($basename));
                    if ($dotPos = strrpos($basename, '.')) {
                        $basename = substr($basename, 0, $dotPos) . '--' . $timestamp . substr($basename, $dotPos);
                    } else {
                        $basename .= '--' . $timestamp;
                    }
                    $dumpFile = $prefix . $basename;
                }

                // Make the filename absolute.
                $dumpFile = $fs->makePathAbsolute($dumpFile);

                // Ensure the filename is not a directory.
                if (is_dir($dumpFile)) {
                    $dumpFile .= '/' . $this->getDefaultDumpFilename($project, $environment, $appName, $timestamp);
                }
            } else {
                $projectRoot = $this->getProjectRoot();
                $directory = $projectRoot ?: getcwd();
                $dumpFile = $directory
                    . '/' . $this->getDefaultDumpFilename($project, $environment, $appName, $timestamp);
            }
        }

        if (isset($dumpFile)) {
            if (file_exists($dumpFile)) {
                /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
                $questionHelper = $this->getService('question_helper');
                if (!$questionHelper->confirm("File exists: <comment>$dumpFile</comment>. Overwrite?", false)) {
                    return 1;
                }
            }
            $this->stdErr->writeln("Creating SQL dump file: <info>$dumpFile</info>");
        }

        /** @var \Platformsh\Cli\Service\Relationships $relationships */
        $relationships = $this->getService('relationships');

        $database = $relationships->chooseDatabase($sshUrl, $input);
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

        /** @var \Platformsh\Cli\Service\Ssh $ssh */
        $ssh = $this->getService('ssh');
        $command = $ssh->getSshCommand()
            . ' -C ' . escapeshellarg($sshUrl)
            . ' ' . escapeshellarg($dumpCommand);
        if (isset($dumpFile)) {
            $command .= ' > ' . escapeshellarg($dumpFile);
        }

        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');

        return $shell->executeSimple($command);
    }

    /**
     * Get the default filename for an SQL dump.
     *
     * @param Project     $project
     * @param Environment $environment
     * @param string|null $appName
     * @param string|null $timestamp
     *
     * @return string
     */
    protected function getDefaultDumpFilename(Project $project, Environment $environment, $appName = null, $timestamp = null)
    {
        $filename = $project->id . '--' . $environment->id;
        if ($appName !== null) {
            $filename .= '--' . $appName;
        }
        if ($timestamp !== null) {
            $filename .= '--' . $timestamp;
        }
        $filename .= '--dump.sql';

        return $filename;
    }
}
