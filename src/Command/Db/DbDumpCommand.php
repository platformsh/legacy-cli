<?php
namespace Platformsh\Cli\Command\Db;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Util\RelationshipsUtil;
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
            ->setDescription('Create a local dump of the remote database')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'A filename where the dump should be saved. Defaults to "<project ID>--<environment ID>--dump.sql" in the project root')
            ->addOption('timestamp', 't', InputOption::VALUE_NONE, 'Add a timestamp to the dump filename')
            ->addOption('stdout', null, InputOption::VALUE_NONE, 'Output to STDOUT instead of a file');
        $this->addProjectOption()->addEnvironmentOption()->addAppOption();
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
                /** @var \Platformsh\Cli\Helper\FilesystemHelper $fsHelper */
                $fsHelper = $this->getHelper('fs');

                // Insert the timestamp into the filename.
                if ($timestamp) {
                    $basename = basename($dumpFile);
                    $prefix = substr($dumpFile, 0, - strlen($basename));
                    if ($dotPos = strrpos($basename, '.')) {
                        $basename = substr($basename, 0, $dotPos) . '--' . $timestamp . substr($basename, $dotPos);
                    }
                    else {
                        $basename .= '--' . $timestamp;
                    }
                    $dumpFile = $prefix . $basename;
                }

                // Make the filename absolute.
                $dumpFile = $fsHelper->makePathAbsolute($dumpFile);

                // Ensure the filename is not a directory.
                if (is_dir($dumpFile)) {
                    $dumpFile .= '/' . $this->getDefaultDumpFilename($project, $environment, $appName, $timestamp);
                }
            }
            else {
                if (!$projectRoot = $this->getProjectRoot()) {
                    throw new RootNotFoundException(
                        'Project root not found. Specify --file or go to a project directory.'
                    );
                }
                $dumpFile = $projectRoot . '/' . $this->getDefaultDumpFilename($project, $environment, $appName, $timestamp);
            }
        }

        if (isset($dumpFile)) {
            if (file_exists($dumpFile)) {
                /** @var \Platformsh\Cli\Helper\QuestionHelper $questionHelper */
                $questionHelper = $this->getHelper('question');
                if (!$questionHelper->confirm("File exists: <comment>$dumpFile</comment>. Overwrite?", false)) {
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

        $command = 'ssh -C ' . escapeshellarg($sshUrl)
            . ' ' . escapeshellarg($dumpCommand);
        if (isset($dumpFile)) {
            $command .= ' > ' . escapeshellarg($dumpFile);
        }

        return $this->getHelper('shell')->executeSimple($command);
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
