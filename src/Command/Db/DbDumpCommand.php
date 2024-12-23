<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Db;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\Git;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\Host\RemoteHost;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db:dump', description: 'Create a local dump of the remote database')]
class DbDumpCommand extends CommandBase
{
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Filesystem $filesystem, private readonly Git $git, private readonly QuestionHelper $questionHelper, private readonly Relationships $relationships, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('schema', null, InputOption::VALUE_REQUIRED, 'The schema to dump. Omit to use the default schema (usually "main").')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'A custom filename for the dump')
            ->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'A custom directory for the dump')
            ->addOption('gzip', 'z', InputOption::VALUE_NONE, 'Compress the dump using gzip')
            ->addOption('timestamp', 't', InputOption::VALUE_NONE, 'Add a timestamp to the dump filename')
            ->addOption('stdout', 'o', InputOption::VALUE_NONE, 'Output to STDOUT instead of a file')
            ->addOption('table', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Table(s) to include')
            ->addOption('exclude-table', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Table(s) to exclude')
            ->addOption('schema-only', null, InputOption::VALUE_NONE, 'Dump only schemas, no data')
            ->addOption('charset', null, InputOption::VALUE_REQUIRED, 'The character set encoding for the dump');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->selector->addAppOption($this->getDefinition());
        $this->addCompleter($this->selector);
        Relationships::configureInput($this->getDefinition());
        Ssh::configureInput($this->getDefinition());
        $this->setHiddenAliases(['sql-dump', 'environment:sql-dump']);
        $this->addExample('Create an SQL dump file');
        $this->addExample('Create a gzipped SQL dump file named "dump.sql.gz"', '--gzip -f dump.sql.gz');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selectorConfig = new SelectorConfig(
            envRequired: false,
            allowLocalHost: $this->relationships->hasLocalEnvVar(),
            chooseEnvFilter: SelectorConfig::filterEnvsMaybeActive(),
        );
        // TODO check if this still allows offline use from the container
        $selection = $this->selector->getSelection($input, $selectorConfig);
        $host = $this->selector->getHostFromSelection($input, $selection);

        $timestamp = $input->getOption('timestamp') ? date('Ymd-His-T') : null;
        $gzip = $input->getOption('gzip');
        $includedTables = $input->getOption('table');
        $excludedTables = $input->getOption('exclude-table');
        $schemaOnly = $input->getOption('schema-only');
        $projectRoot = $this->selector->getProjectRoot();

        $database = $this->relationships->chooseDatabase($host, $input, $output);
        if (empty($database)) {
            return 1;
        }

        $service = false;
        if ($selection->hasEnvironment()) {
            // Get information about the deployed service associated with the
            // selected relationship.
            $deployment = $this->api->getCurrentDeployment($selection->getEnvironment());
            $service = isset($database['service']) ? $deployment->getService($database['service']) : false;
        }

        $schema = $input->getOption('schema');
        if (empty($schema)) {
            // Get a list of schemas (database names) from the service configuration.
            $schemas = $service ? $this->relationships->getServiceSchemas($service) : [];

            // Filter the list by the schemas accessible from the endpoint.
            if (isset($database['rel'])
                && $service
                && isset($service->configuration['endpoints'][$database['rel']]['privileges'])) {
                $schemas = array_intersect(
                    $schemas,
                    array_keys($service->configuration['endpoints'][$database['rel']]['privileges']),
                );
            }

            // If the database path is not in the list of schemas, we have to
            // use that.
            if (!empty($database['path']) && !in_array($database['path'], $schemas, true)) {
                $schemas = [$database['path']];
            }

            // Provide the user with a choice of schemas.
            foreach ($schemas as $schema) {
                $choices[$schema] = $schema;
                if ($schema === $database['path']) {
                    $choices[$schema] .= ' (default)';
                }
            }
            $schema = null;
            if (!empty($choices)) {
                $schema = $this->questionHelper->choose($choices, 'Enter a number to choose a schema:', $database['path'], true);
            }
            if (empty($schema)) {
                $this->stdErr->writeln('The --schema is required.');
                if (!empty($schemas)) {
                    $this->stdErr->writeln('Available schemas: ' . implode(', ', $schemas));
                }

                return 1;
            }
        }

        $dumpFile = null;
        if (!$input->getOption('stdout')) {
            // Process the user --file option.
            if ($fileOption = $input->getOption('file')) {
                if (is_dir($fileOption)) {
                    $this->stdErr->writeln(sprintf('Filename is a directory: <error>%s</error>', $fileOption));
                    $this->stdErr->writeln('Use the --directory option to specify a directory.');

                    return 1;
                }
                $dumpFile = rtrim((string) $fileOption, '/');
                if (!$gzip && preg_match('/\.gz$/i', $dumpFile)) {
                    $this->stdErr->writeln('Warning: the filename ends with ".gz", but the dump will be plain-text.');
                    $this->stdErr->writeln('Use <comment>--gzip</comment> to create a compressed dump.');
                    $this->stdErr->writeln('');
                }
            } else {
                $defaultFilename = $this->getDefaultFilename(
                    $selection->hasEnvironment() ? $selection->getEnvironment() : null,
                    $database['service'],
                    $schema,
                    $includedTables,
                    $excludedTables,
                    $schemaOnly,
                    $gzip,
                );
                $dumpFile = $projectRoot ? $projectRoot . '/' . $defaultFilename : $defaultFilename;
            }

            // Process the user --directory option.
            if ($directoryOption = $input->getOption('directory')) {
                if (!is_dir($directoryOption)) {
                    $this->stdErr->writeln(sprintf('Directory not found: <error>%s</error>', $directoryOption));

                    return 1;
                }
                $dumpFile = rtrim((string) $directoryOption, '/') . '/' . basename($dumpFile);
            }

            // Insert a timestamp into the filename, before the
            // extension.
            if ($timestamp !== null && !str_contains($dumpFile, $timestamp)) {
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
                if (!$this->questionHelper->confirm("File exists: <comment>$dumpFile</comment>. Overwrite?")) {
                    return 1;
                }
            }
            $this->stdErr->writeln(sprintf(
                'Creating %s file: <info>%s</info>',
                $gzip ? 'gzipped SQL dump' : 'SQL dump',
                $dumpFile,
            ));
        }

        switch ($database['scheme']) {
            case 'pgsql':
                $dumpCommand = 'pg_dump --no-owner --if-exists --clean --blobs ' . $this->relationships->getDbCommandArgs('pg_dump', $database, $schema);
                if ($schemaOnly) {
                    $dumpCommand .= ' --schema-only';
                }
                foreach ($includedTables as $table) {
                    $dumpCommand .= ' ' . OsUtil::escapePosixShellArg('--table=' . $table);
                }
                foreach ($excludedTables as $table) {
                    $dumpCommand .= ' ' . OsUtil::escapePosixShellArg('--exclude-table=' . $table);
                }
                if ($input->getOption('charset') !== null) {
                    $dumpCommand .= ' ' . OsUtil::escapePosixShellArg('--encoding=' . $input->getOption('charset'));
                }
                if ($output->isVeryVerbose()) {
                    $dumpCommand .= ' --verbose';
                }
                break;

            default:
                $cmdName = $this->relationships->isMariaDB($database) ? 'mariadb-dump' : 'mysqldump';
                $cmdInvocation = $this->relationships->mariaDbCommandWithFallback($cmdName);
                $dumpCommand = $cmdInvocation . ' --single-transaction '
                    . $this->relationships->getDbCommandArgs($cmdName, $database, $schema);
                if ($schemaOnly) {
                    $dumpCommand .= ' --no-data';
                }
                foreach ($excludedTables as $table) {
                    $dumpCommand .= ' ' . OsUtil::escapePosixShellArg(sprintf('--ignore-table=%s.%s', $database['path'], $table));
                }
                if ($includedTables) {
                    $dumpCommand .= ' --tables '
                        . implode(' ', array_map(fn($table) => OsUtil::escapePosixShellArg($table), $includedTables));
                }
                if (!empty($service->configuration['properties']['max_allowed_packet'])) {
                    $dumpCommand .= ' --max_allowed_packet=' . $service->configuration['properties']['max_allowed_packet'] . 'MB';
                }
                if ($input->getOption('charset') !== null) {
                    $dumpCommand .= ' ' . OsUtil::escapePosixShellArg('--default-character-set=' . $input->getOption('charset'));
                }
                if ($output->isVeryVerbose()) {
                    $dumpCommand .= ' --verbose';
                }
                break;
        }

        if ($gzip) {
            // If dump compression is enabled, pipe the dump command into gzip.
            $dumpCommand .= ' | gzip --stdout';

            // If it's supported, then switch on "pipefail" to ensure a
            // non-zero exit code is returned if any part of the pipe fails.
            $setOptions = $host->runCommand('set -o', false);
            if (is_string($setOptions) && stripos($setOptions, 'pipefail') !== false) {
                $dumpCommand = 'set -o pipefail; ' . $dumpCommand;
            }
        } elseif ($host instanceof RemoteHost) {
            // If dump compression is not enabled, data can still be compressed
            // transparently as it's streamed over the SSH connection.
            $host->setExtraSshOptions(['Compression yes']);
        }

        $append = '';
        if ($dumpFile) {
            $append .= ' > ' . escapeshellarg($dumpFile);
        }

        set_time_limit(0);

        // Execute the command.
        $start = microtime(true);
        $exitCode = $host->runCommandDirect($dumpCommand, $append);

        if ($exitCode === 0) {
            $this->stdErr->writeln('The dump completed successfully', OutputInterface::VERBOSITY_VERBOSE);
            $this->stdErr->writeln(sprintf('  Time: %ss', number_format(microtime(true) - $start, 2)), OutputInterface::VERBOSITY_VERBOSE);
            if ($dumpFile && ($size = filesize($dumpFile)) !== false) {
                $this->stdErr->writeln(sprintf('  Size: %s', Helper::formatMemory($size)), OutputInterface::VERBOSITY_VERBOSE);
            }
        }

        // If a dump file exists, check that it's excluded in the project's
        // .gitignore configuration.
        if ($dumpFile && file_exists($dumpFile) && $projectRoot && str_starts_with($dumpFile, $projectRoot)) {
            $git = $this->git;
            if (!$git->checkIgnore($dumpFile, $projectRoot)) {
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
     * Generates the default dump filename.
     *
     * @param string[] $includedTables
     * @param string[] $excludedTables
     */
    private function getDefaultFilename(
        ?Environment $environment = null,
        ?string $dbServiceName = null,
        ?string $schema = null,
        array $includedTables = [],
        array $excludedTables = [],
        bool $schemaOnly = false,
        bool $gzip = false,
    ): string {
        $prefix = $this->config->getStr('service.env_prefix');
        $projectId = $environment ? $environment->project : getenv($prefix . 'PROJECT');
        $environmentMachineName = $environment ? $environment->machine_name : getenv($prefix . 'ENVIRONMENT');
        $defaultFilename = $projectId ?: 'db';
        if ($environmentMachineName) {
            $defaultFilename .= '--' . $environmentMachineName;
        }
        if ($dbServiceName !== null) {
            $defaultFilename .= '--' . $dbServiceName;
        }
        if ($schema !== null) {
            $defaultFilename .= '--' . $schema;
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
