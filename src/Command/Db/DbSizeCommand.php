<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Db;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Symfony\Component\Process\Exception\RuntimeException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\Host\HostInterface;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Client\Model\Deployment\Service;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db:size', description: 'Estimate the disk usage of a database')]
class DbSizeCommand extends CommandBase
{
    /** @var array<string, string> */
    private array $tableHeader = ['max' => 'Allocated disk', 'used' => 'Estimated usage', 'percent_used' => '% used'];
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Io $io, private readonly QuestionHelper $questionHelper, private readonly Relationships $relationships, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    public const RED_WARNING_THRESHOLD = 90;//percentage
    public const YELLOW_WARNING_THRESHOLD = 80;//percentage
    public const BYTE_TO_MEGABYTE = 1048576;
    public const WASTED_SPACE_WARNING_THRESHOLD = 200;//percentage

    public const ESTIMATE_WARNING = 'This is an estimate of the database disk usage. The real size on disk is usually higher because of overhead.';

    protected function configure(): void
    {
        $this
            ->addOption('bytes', 'B', InputOption::VALUE_NONE, 'Show sizes in bytes.')
            ->addOption('cleanup', 'C', InputOption::VALUE_NONE, 'Check if tables can be cleaned up and show me recommendations (InnoDb only).');
        $help = self::ESTIMATE_WARNING;
        if ($this->config->getBool('api.metrics')) {
            $this->stability = self::STABILITY_DEPRECATED;
            $help .= "\n\n";
            $help .= '<options=bold;fg=yellow>Deprecated:</>';
            $help .= "\nThis command is deprecated and will be removed in a future version.\n";
            $help .= \sprintf('To see more accurate disk usage, run: <comment>%s disk</comment>', $this->config->getStr('application.executable'));
        }
        $this->setHelp($help);
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->selector->addAppOption($this->getDefinition());
        $this->addCompleter($this->selector);
        Relationships::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition(), $this->tableHeader);
        Ssh::configureInput($this->getDefinition());
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(allowLocalHost: $this->relationships->hasLocalEnvVar(), chooseEnvFilter: SelectorConfig::filterEnvsMaybeActive()));
        $host = $this->selector->getHostFromSelection($input, $selection);

        $database = $this->relationships->chooseDatabase($host, $input, $output, ['mysql', 'pgsql', 'mongodb']);
        if (empty($database)) {
            $this->stdErr->writeln('No database selected.');
            return 1;
        }
        if (!isset($database['service'])) {
            $this->stdErr->writeln('Unable to find database service information.');
            return 1;
        }
        $dbServiceName = $database['service'];

        // Get information about the deployed service associated with the
        // selected relationship.
        $deployment = $this->api->getCurrentDeployment($selection->getEnvironment());
        $service = $deployment->getService($dbServiceName);

        $this->stdErr->writeln(sprintf('Checking database service <comment>%s</comment>...', $dbServiceName));

        $this->io->debug('Calculating estimated usage...');
        $allocatedDisk = ((int) $service->disk) * self::BYTE_TO_MEGABYTE;
        $estimatedUsage = $this->getEstimatedUsage($host, $database);
        $percentageUsed = round($estimatedUsage * 100 / $allocatedDisk);
        $machineReadable = $this->table->formatIsMachineReadable();
        $showInBytes = $input->getOption('bytes') || $machineReadable;

        $values = [
            'max' => $showInBytes ? (string) $allocatedDisk : Helper::formatMemory($allocatedDisk),
            'used' => $showInBytes ? (string) $estimatedUsage : Helper::formatMemory((int) $estimatedUsage),
            'percent_used' => $this->formatPercentage($percentageUsed, $machineReadable),
        ];

        $this->stdErr->writeln('');
        $this->table->render([$values], $this->tableHeader);

        $this->showWarnings($percentageUsed);

        $this->showInaccessibleSchemas($service, $database);

        if ($database['scheme'] === 'mysql' && $estimatedUsage > 0 && $input->getOption('cleanup')) {
            $this->checkInnoDbTablesInNeedOfOptimizing($host, $database, $input);
        }

        return 0;
    }

    /**
     * Returns a list of cleanup queries for a list of tables.
     *
     * @param string[] $rows
     *
     * @see DbSizeCommand::checkInnoDbTablesInNeedOfOptimizing()
     *
     * @return string[]
     */
    private function getCleanupQueries(array $rows): array
    {
        return array_filter(
            array_map(function ($row): ?string {
                if (!strpos($row, "\t")) {
                    return null;
                }
                [$schema, $table] = explode("\t", $row);

                return sprintf('ALTER TABLE `%s`.`%s` ENGINE="InnoDB";', $schema, $table);
            }, $rows),
        );
    }

    /**
     * Displays a list of InnoDB tables that can be usefully cleaned up.
     *
     * @param array<string, mixed> $database
     */
    private function checkInnoDbTablesInNeedOfOptimizing(HostInterface $host, array $database, InputInterface $input): void
    {
        $tablesNeedingCleanup = $host->runCommand($this->getMysqlCommand($database), true, true, $this->mysqlTablesInNeedOfOptimizing());
        $queries = [];
        if (is_string($tablesNeedingCleanup)) {
            $queries = $this->getCleanupQueries(explode("\n", $tablesNeedingCleanup));
        }

        if (!count($queries)) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('No optimizations found.');
            return;
        }

        $this->stdErr->writeln('');
        $this->stdErr->writeln('You can save space by running the following commands during a maintenance window:');
        $this->stdErr->writeln('');
        foreach ($queries as $query) {
            $this->stdErr->writeln($query);
        }

        $this->stdErr->writeln('');
        $this->stdErr->writeln('<comment>Warning:</comment> Running these may lock up your database for several minutes.');
        $this->stdErr->writeln("Only run these when you know what you're doing.");
        $this->stdErr->writeln('');
        if ($input->isInteractive() && $this->questionHelper->confirm('Do you want to run these queries now?', false)) {
            $mysqlCommand = $this->getMysqlCommand($database);
            foreach ($queries as $query) {
                $this->stdErr->write($query);
                $host->runCommand($mysqlCommand, true, true, $query);
                $this->stdErr->writeln('<options=bold;fg=green> [OK]</>');
            }
        }
    }

    /**
     * Shows a warning about schemas not accessible through this relationship.
     *
     * @param Service $service
     * @param array<string, mixed> $database
     *
     * @return void
     */
    private function showInaccessibleSchemas(Service $service, array $database): void
    {
        // Find if not all the available schemas were accessible via this relationship.
        if (isset($database['rel'])
            && isset($service->configuration['endpoints'][$database['rel']]['privileges'])) {
            $schemas = !empty($service->configuration['schemas'])
                ? $service->configuration['schemas']
                : ['main'];
            $accessible = array_keys($service->configuration['endpoints'][$database['rel']]['privileges']);
            $missing = array_diff($schemas, $accessible);
            if (!empty($missing)) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('Additionally, not all schemas are accessible through this endpoint.');
                $this->stdErr->writeln('  Endpoint:             ' . $database['rel']);
                $this->stdErr->writeln('  Accessible schemas:   <info>' . implode(', ', $accessible) . '</info>');
                $this->stdErr->writeln('  Inaccessible schemas: <comment>' . implode(', ', $missing) . '</comment>');
            }
        }
    }

    /**
     * Shows size warnings based on the estimated disk use percentage.
     *
     * @param int|float $percentageUsed
     *
     * @return void
     */
    private function showWarnings(int|float $percentageUsed): void
    {
        if ($percentageUsed > self::RED_WARNING_THRESHOLD) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('<options=bold;fg=red>Warning:</>');
            $this->stdErr->writeln('Databases tend to need extra space for starting up and temporary storage when running large queries.');
            $this->stdErr->writeln(sprintf('Please increase the allocated space in %s', $this->config->getStr('service.project_config_dir') . '/services.yaml'));
        }

        $this->stdErr->writeln('');

        if ($this->config->getBool('api.metrics') && $this->config->isCommandEnabled('metrics:disk')) {
            $this->stdErr->writeln('<options=bold;fg=yellow>Deprecated:</>');
            $this->stdErr->writeln('This command is deprecated and will be removed in a future version.');
            $this->stdErr->writeln(\sprintf('To see more accurate disk usage, run: <comment>%s disk</comment>', $this->config->getStr('application.executable')));
        } else {
            $this->stdErr->writeln('<options=bold;fg=yellow>Warning:</>');
            $this->stdErr->writeln(self::ESTIMATE_WARNING);
        }
    }

    /**
     * Returns a query to find disk usage for a PostgreSQL database.
     *
     * @return string
     */
    private function psqlQuery(): string
    {
        //both these queries are wrong...
        //$query = 'SELECT SUM(pg_database_size(t1.datname)) as size FROM pg_database t1'; //does miss lots of data
        //$query = 'SELECT SUM(pg_total_relation_size(pg_class.oid)) AS size FROM pg_class LEFT OUTER JOIN pg_namespace ON (pg_namespace.oid = pg_class.relnamespace)';

        //but running both, and taking the average, gets us closer to the correct value
        return 'SELECT AVG(size) FROM (SELECT SUM(pg_database_size(t1.datname)) as size FROM pg_database t1 UNION SELECT SUM(pg_total_relation_size(pg_class.oid)) AS size FROM pg_class LEFT OUTER JOIN pg_namespace ON (pg_namespace.oid = pg_class.relnamespace)) x;';
    }

    /**
     * Returns the psql CLI client command.
     *
     * @param array<string, mixed> $database
     *
     * @return string
     */
    private function getPsqlCommand(array $database): string
    {
        $dbUrl = $this->relationships->getDbCommandArgs('psql', $database, '');

        return sprintf(
            'psql --echo-hidden -t --no-align %s',
            $dbUrl,
        );
    }

    /**
     * @param array<string, mixed> $database
     * @return string
     */
    private function getMongoDbCommand(array $database): string
    {
        $dbUrl = $this->relationships->getDbCommandArgs('mongo', $database);

        return sprintf(
            'mongo %s --quiet --eval %s',
            $dbUrl,
            // See https://docs.mongodb.com/manual/reference/command/dbStats/
            OsUtil::escapePosixShellArg('db.stats().fsUsedSize'),
        );
    }

    /**
     * Returns the mysql CLI client command.
     *
     * @param array<string, mixed> $database
     *
     * @return string
     */
    private function getMysqlCommand(array $database): string
    {
        $cmdName = $this->relationships->isMariaDB($database) ? 'mariadb' : 'mysql';
        $cmdInvocation = $this->relationships->mariaDbCommandWithFallback($cmdName);
        $connectionParams = $this->relationships->getDbCommandArgs($cmdName, $database, '');

        return sprintf(
            '%s %s --no-auto-rehash --raw --skip-column-names',
            $cmdInvocation,
            $connectionParams,
        );
    }

    /**
     * Returns a command to query table size of non-InnoDB using tables for a MySQL database in MB
     *
     * @param bool  $excludeInnoDb
     *
     * @return string
     */
    private function mysqlNonInnodbQuery(bool $excludeInnoDb = true): string
    {
        return 'SELECT'
            . ' ('
            . 'SUM(data_length+index_length+data_free)'
            . ' + (COUNT(*) * 300 * 1024)'
            . ')'
            . ' AS estimated_actual_disk_usage'
            . ' FROM information_schema.tables'
            . ($excludeInnoDb ? ' WHERE ENGINE <> "InnoDB"' : '')
            . ';';
    }

    /**
     * Returns a MySQL query to find disk usage for all InnoDB tables.
     *
     * @return string
     */
    private function mysqlInnodbQuery(): string
    {
        return 'SELECT SUM(ALLOCATED_SIZE) FROM information_schema.innodb_sys_tablespaces;';
    }

    /**
     * Returns a MySQL query to find if the InnoDB ALLOCATED_SIZE column exists.
     *
     * @return string
     */
    private function mysqlInnodbAllocatedSizeExists(): string
    {
        return 'SELECT count(COLUMN_NAME) FROM information_schema.COLUMNS WHERE table_schema ="information_schema" AND table_name="innodb_sys_tablespaces" AND column_name LIKE "ALLOCATED_SIZE";';
    }

    /**
     * Returns a MySQL query to find InnoDB tables needing optimization.
     *
     * @return string
     */
    private function mysqlTablesInNeedOfOptimizing(): string
    {
        /*, data_free, data_length, ((data_free+1)/(data_length+1))*100 as wasted_space_percentage*/
        return 'SELECT TABLE_SCHEMA, TABLE_NAME FROM information_schema.tables WHERE ENGINE = "InnoDB" AND TABLE_TYPE="BASE TABLE" AND ((data_free+1)/(data_length+1))*100 >= ' . self::WASTED_SPACE_WARNING_THRESHOLD . ' ORDER BY data_free DESC LIMIT 10';
    }

    /**
     * Estimates usage of a database.
     *
     * @param HostInterface $host
     * @param array<string, mixed> $database
     *
     * @return float Estimated usage in bytes.
     */
    private function getEstimatedUsage(HostInterface $host, array $database): float
    {
        return match ($database['scheme']) {
            'pgsql' => $this->getPgSqlUsage($host, $database),
            'mongodb' => $this->getMongoDbUsage($host, $database),
            default => $this->getMySqlUsage($host, $database),
        };
    }

    /**
     * Estimates usage of a PostgreSQL database.
     *
     * @param HostInterface $host
     * @param array<string, mixed> $database
     *
     * @return float Estimated usage in bytes
     */
    private function getPgSqlUsage(HostInterface $host, array $database): float
    {
        return (float) $host->runCommand($this->getPsqlCommand($database), input: $this->psqlQuery());
    }

    /**
     * @param HostInterface $host
     * @param array<string, mixed> $database
     * @return float
     */
    private function getMongoDbUsage(HostInterface $host, array $database): float
    {
        return (float) $host->runCommand($this->getMongoDbCommand($database));
    }

    /**
     * Estimates usage of a MySQL database.
     *
     * @param HostInterface $host
     * @param array<string, mixed> $database
     *
     * @return float Estimated usage in bytes
     */
    private function getMySqlUsage(HostInterface $host, array $database): float
    {
        $this->io->debug('Getting MySQL usage...');
        $allocatedSizeSupported = $host->runCommand($this->getMysqlCommand($database), input: $this->mysqlInnodbAllocatedSizeExists());
        $innoDbSize = 0;
        if ($allocatedSizeSupported) {
            $this->io->debug('Checking InnoDB separately for more accurate results...');
            try {
                $innoDbSize = $host->runCommand($this->getMysqlCommand($database), input: $this->mysqlInnodbQuery());
            } catch (RuntimeException $e) {
                // Some configurations do not have PROCESS privilege(s) and thus have no access to the sys_tablespaces
                // table. Ignore MySQL's 1227 Access Denied error, and revert to the legacy calculation.
                if (stripos($e->getMessage(), 'access denied') !== false) {
                    $this->io->debug('InnoDB checks not available: ' . $e->getMessage());
                    $allocatedSizeSupported = false;
                } else {
                    throw $e;
                }
            }
        }

        $otherSizes = $host->runCommand($this->getMysqlCommand($database), input: $this->mysqlNonInnodbQuery((bool) $allocatedSizeSupported));

        return (float) $otherSizes + (float) $innoDbSize;
    }

    /**
     * Formats a size percentage with coloring.
     *
     * @param int|float $percentage
     * @param bool      $machineReadable
     *
     * @return string
     */
    private function formatPercentage(int|float $percentage, bool $machineReadable): string
    {
        if ($machineReadable) {
            $format = '%d';
        } elseif ($percentage > self::RED_WARNING_THRESHOLD) {
            $format = '<options=bold;fg=red>~ %d%%</>';
        } elseif ($percentage > self::YELLOW_WARNING_THRESHOLD) {
            $format = '<options=bold;fg=yellow>~ %d%%</>';
        } else {
            $format = '<options=bold;fg=green>~ %d%%</>';
        }

        return sprintf($format, round($percentage));
    }
}
