<?php
namespace Platformsh\Cli\Command\Db;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\Git;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DbDumpCommand extends CommandBase
{
    protected static $defaultName = 'db:dump';

    private $filesystem;
    private $git;
    private $questionHelper;
    private $relationships;
    private $selector;
    private $shell;
    private $ssh;

    public function __construct(
        Filesystem $filesystem,
        Git $git,
        QuestionHelper $questionHelper,
        Relationships $relationships,
        Selector $selector,
        Shell $shell,
        Ssh $ssh
    ) {
        $this->filesystem = $filesystem;
        $this->git = $git;
        $this->questionHelper = $questionHelper;
        $this->relationships = $relationships;
        $this->selector = $selector;
        $this->shell = $shell;
        $this->ssh = $ssh;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Create a local dump of the remote database');
        $this->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'A custom filename for the dump')
            ->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'A custom directory for the dump')
            ->addOption('gzip', 'z', InputOption::VALUE_NONE, 'Compress the dump using gzip')
            ->addOption('timestamp', 't', InputOption::VALUE_NONE, 'Add a timestamp to the dump filename')
            ->addOption('stdout', 'o', InputOption::VALUE_NONE, 'Output to STDOUT instead of a file')
            ->addOption('table', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Table(s) to include')
            ->addOption('exclude-table', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Table(s) to exclude')
            ->addOption('schema-only', null, InputOption::VALUE_NONE, 'Dump only schemas, no data');

        $definition = $this->getDefinition();
        $this->selector->addAllOptions($definition);
        $this->relationships->configureInput($definition);
        $this->ssh->configureInput($definition);

        $this->setHiddenAliases(['sql-dump', 'environment:sql-dump']);
        $this->addExample('Create an SQL dump file');
        $this->addExample('Create a gzipped SQL dump file named "dump.sql.gz"', '--gzip -f dump.sql.gz');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);
        $projectRoot = $this->selector->getProjectRoot();
        $environment = $selection->getEnvironment();
        $appName = $selection->getAppName();
        $sshUrl = $environment->getSshUrl($appName);
        $timestamp = $input->getOption('timestamp') ? date('Ymd-His-T') : null;
        $gzip = $input->getOption('gzip');
        $includedTables = $input->getOption('table');
        $excludedTables = $input->getOption('exclude-table');
        $schemaOnly = $input->getOption('schema-only');

        $dumpFile = null;
        if (!$input->getOption('stdout')) {
            // Process the user --file option.
            if ($fileOption = $input->getOption('file')) {
                if (is_dir($fileOption)) {
                    $this->stdErr->writeln(sprintf('Filename is a directory: <error>%s</error>', $fileOption));
                    $this->stdErr->writeln('Use the --directory option to specify a directory.');

                    return 1;
                }
                $dumpFile = rtrim($fileOption, '/');
                if (!$gzip && preg_match('/\.gz$/i', $dumpFile)) {
                    $this->stdErr->writeln('Warning: the filename ends with ".gz", but the dump will be plain-text.');
                    $this->stdErr->writeln('Use <comment>--gzip</comment> to create a compressed dump.');
                    $this->stdErr->writeln('');
                }
            } else {
                $defaultFilename = $this->getDefaultFilename(
                    $environment,
                    $appName,
                    $includedTables,
                    $excludedTables,
                    $schemaOnly,
                    $gzip
                );
                $dumpFile = $projectRoot ? $projectRoot . '/' . $defaultFilename : $defaultFilename;
            }

            // Process the user --directory option.
            if ($directoryOption = $input->getOption('directory')) {
                if (!is_dir($directoryOption)) {
                    $this->stdErr->writeln(sprintf('Directory not found: <error>%s</error>', $directoryOption));

                    return 1;
                }
                $dumpFile = rtrim($directoryOption, '/') . '/' . basename($dumpFile);
            }

            // Insert a timestamp into the filename, before the
            // extension.
            if ($timestamp !== null && strpos($dumpFile, $timestamp) === false) {
                $basename = basename($dumpFile);
                $prefix = substr($dumpFile, 0, - strlen($basename));
                if (($dotPos = strpos($basename, '.')) > 0) {
                    $basenameWithTimestamp = substr($basename, 0, $dotPos) . '--' . $timestamp . substr($basename, $dotPos);
                } else {
                    $basenameWithTimestamp = $basename . '--' . $timestamp;
                }
                $dumpFile = $prefix . $basenameWithTimestamp;
            }

            // Make the filename absolute.
            $dumpFile = $this->filesystem->makePathAbsolute($dumpFile);
        }

        if ($dumpFile) {
            if (file_exists($dumpFile)) {
                if (!$this->questionHelper->confirm("File exists: <comment>$dumpFile</comment>. Overwrite?", false)) {
                    return 1;
                }
            }
            $this->stdErr->writeln(sprintf(
                'Creating %s file: <info>%s</info>',
                $gzip ? 'gzipped SQL dump' : 'SQL dump',
                $dumpFile
            ));
        }

        $database = $this->relationships->chooseDatabase($sshUrl, $input, $output);
        if (empty($database)) {
            return 1;
        }

        switch ($database['scheme']) {
            case 'pgsql':
                $dumpCommand = 'pg_dump --clean --blobs ' . $this->relationships->getDbCommandArgs('pg_dump', $database);
                if ($schemaOnly) {
                    $dumpCommand .= ' --schema-only';
                }
                foreach ($includedTables as $table) {
                    $dumpCommand .= ' ' . OsUtil::escapePosixShellArg('--table=' . $table);
                }
                foreach ($excludedTables as $table) {
                    $dumpCommand .= ' ' . OsUtil::escapePosixShellArg('--exclude-table=' . $table);
                }
                break;

            default:
                $dumpCommand = 'mysqldump --single-transaction '
                    . $this->relationships->getDbCommandArgs('mysqldump', $database);
                if ($schemaOnly) {
                    $dumpCommand .= ' --no-data';
                }
                foreach ($excludedTables as $table) {
                    $dumpCommand .= ' ' . OsUtil::escapePosixShellArg(sprintf('--ignore-table=%s.%s', $database['path'], $table));
                }
                if ($includedTables) {
                    $dumpCommand .= ' --tables '
                        . implode(' ', array_map(function ($table) {
                            return OsUtil::escapePosixShellArg($table);
                        }, $includedTables));
                }
                break;
        }

        $sshCommand = $this->ssh->getSshCommand();

        if ($gzip) {
            // If dump compression is enabled, pipe the dump command into gzip,
            // but not before switching on "pipefail" to ensure a non-zero exit
            // code is returned if any part of the pipe fails.
            $dumpCommand = 'set -o pipefail;'
                . $dumpCommand
                . ' | gzip --stdout';
        } else {
            // If dump compression is not enabled, data can still be compressed
            // transparently as it's streamed over the SSH connection.
            $sshCommand .= ' -C';
        }

        set_time_limit(0);

        // Build the complete SSH command.
        $command = $sshCommand
            . ' ' . escapeshellarg($sshUrl)
            . ' ' . escapeshellarg($dumpCommand);
        if ($dumpFile) {
            $command .= ' > ' . escapeshellarg($dumpFile);
        }

        // Execute the SSH command.
        $start = microtime(true);
        $exitCode = $this->shell->executeSimple($command);

        if ($exitCode === 0) {
            $this->stdErr->writeln('The dump completed successfully', OutputInterface::VERBOSITY_VERBOSE);
            $this->stdErr->writeln(sprintf('  Time: %ss', number_format(microtime(true) - $start, 2)), OutputInterface::VERBOSITY_VERBOSE);
            if ($dumpFile && ($size = filesize($dumpFile)) !== false) {
                $this->stdErr->writeln(sprintf('  Size: %s', Helper::formatMemory($size)), OutputInterface::VERBOSITY_VERBOSE);
            }
        }

        // If a dump file exists, check that it's excluded in the project's
        // .gitignore configuration.
        if ($dumpFile && file_exists($dumpFile) && $projectRoot && strpos($dumpFile, $projectRoot) === 0) {
            if (!$this->git->checkIgnore($dumpFile, $projectRoot)) {
                $this->stdErr->writeln('<comment>Warning: the dump file is not excluded by Git</comment>');
                if ($pos = strrpos($dumpFile, '--dump.sql')) {
                    $extension = substr($dumpFile, $pos);
                    $this->stdErr->writeln('  You should probably exclude these files using .gitignore:');
                    $this->stdErr->writeln('    *' . $extension);
                }
            }
        }

        return $exitCode;
    }

    /**
     * Get the default dump filename.
     *
     * @param Environment $environment
     * @param string|null $appName
     * @param array       $includedTables
     * @param array       $excludedTables
     * @param bool        $schemaOnly
     * @param bool        $gzip
     *
     * @return string
     */
    private function getDefaultFilename(
        Environment $environment,
        $appName = null,
        array $includedTables = [],
        array $excludedTables = [],
        $schemaOnly = false,
        $gzip = false)
    {
        $defaultFilename = $environment->project . '--' . $environment->machine_name;
        if ($appName !== null) {
            $defaultFilename .= '--' . $appName;
        }
        if ($includedTables) {
            $defaultFilename .= '--' . implode(',', $includedTables);
        }
        if ($excludedTables) {
            $defaultFilename .= '--excl-' . implode(',', $excludedTables);
        }
        if ($schemaOnly) {
            $defaultFilename .= '--schema';
        }
        $defaultFilename .= '--dump.sql';
        if ($gzip) {
            $defaultFilename .= '.gz';
        }

        return $defaultFilename;
    }
}
