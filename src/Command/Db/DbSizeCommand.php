<?php

namespace Platformsh\Cli\Command\Db;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\Host\HostInterface;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Client\Model\Deployment\Service;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DbSizeCommand extends CommandBase
{

    const RED_WARNING_THRESHOLD = 90;//percentage
    const YELLOW_WARNING_THRESHOLD = 80;//percentage
    const BYTE_TO_MEGABYTE = 1048576;
    const WASTED_SPACE_WARNING_THRESHOLD = 200;//percentage

    /**
     * {@inheritDoc}
     */
    protected function configure() {
        $this->setName('db:size')
            ->setDescription('Estimate the disk usage of a database')
            ->addOption('bytes', 'B', InputOption::VALUE_NONE, 'Show sizes in bytes.')
            ->addOption('cleanup', 'C', InputOption::VALUE_NONE, 'Check if tables can be cleaned up and show me recommendations (InnoDb only).')
            ->setHelp(
                "This is an estimate of the database disk usage. The real size on disk is usually a bit higher because of overhead."
            );
        $this->addProjectOption()->addEnvironmentOption()->addAppOption();
        Relationships::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition());
        Ssh::configureInput($this->getDefinition());
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output) {
        /** @var \Platformsh\Cli\Service\Relationships $relationships */
        $relationships = $this->getService('relationships');

        $this->validateInput($input);
        $container = $this->selectRemoteContainer($input);

        $host = $this->selectHost($input, $relationships->hasLocalEnvVar(), $container);

        $database = $relationships->chooseDatabase($host, $input, $output, ['mysql', 'pgsql', 'mongodb']);
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
        $deployment = $this->api()->getCurrentDeployment($this->getSelectedEnvironment());
        $service = $deployment->getService($dbServiceName);

        $this->stdErr->writeln(sprintf('Checking database service <comment>%s</comment>...', $dbServiceName));

        $this->debug('Calculating estimated usage...');
        $allocatedDisk = $service->disk * self::BYTE_TO_MEGABYTE;
        $estimatedUsage = $this->getEstimatedUsage($host, $database);
        $percentageUsed = round($estimatedUsage * 100 / $allocatedDisk);

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        $machineReadable = $table->formatIsMachineReadable();
        $showInBytes = $input->getOption('bytes') || $machineReadable;

        $columns  = ['max' => 'Allocated disk', 'used' => 'Estimated usage', 'percent_used' => '% used'];
        $values = [
            'max' => $showInBytes ? $allocatedDisk : Helper::formatMemory($allocatedDisk),
            'used' => $showInBytes ? $estimatedUsage : Helper::formatMemory($estimatedUsage),
            'percent_used' => $this->formatPercentage($percentageUsed, $machineReadable),
        ];

        $this->stdErr->writeln('');
        $table->render([$values], $columns);

        $this->showWarnings($percentageUsed);

        $this->showInaccessibleSchemas($service, $database);

        if ($database['scheme'] === 'mysql' && $estimatedUsage > 0 && $input->getOption('cleanup')) {
            $this->checkInnoDbTablesInNeedOfOptimizing($host, $database);
        }

        return 0;
    }

    /**
     * Returns a list of cleanup queries for a list of tables.
     *
     * @param array $rows
     *
     * @see DbSizeCommand::checkInnoDbTablesInNeedOfOptimizing()
     *
     * @return array
     */
    private function getCleanupQueries(array $rows) {
        return array_filter(
            array_map(function($row) {
                if (!strpos($row, "\t")) {
                    return null;
                }
                list($schema, $table) = explode("\t", $row);

                return sprintf('ALTER TABLE `%s`.`%s` ENGINE="InnoDB";', $schema, $table);
            }, $rows)
        );
    }

    /**
     * Displays a list of InnoDB tables that can be usefully cleaned up.
     *
     * @param HostInterface $host
     * @param array         $database
     *
     * @return void
     */
    private function checkInnoDbTablesInNeedOfOptimizing($host, array $database) {
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

        if ($this->getService('question_helper')->confirm('Do you want to run these queries now?', false)) {
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
     * @param \Platformsh\Client\Model\Deployment\Service $service
     * @param array                                       $database
     *
     * @return void
     */
    private function showInaccessibleSchemas(Service $service, array $database) {
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
    private function showWarnings($percentageUsed) {
        if ($percentageUsed > self::RED_WARNING_THRESHOLD) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('<options=bold;fg=red>Warning</>');
            $this->stdErr->writeln('Databases tend to need extra space for starting up and temporary storage when running large queries.');
            $this->stdErr->writeln(sprintf('Please increase the allocated space in %s', $this->config()->get('service.project_config_dir') . '/services.yaml'));
        }
        $this->stdErr->writeln('');
        $this->stdErr->writeln('<options=bold;fg=yellow>Warning</>');
        $this->stdErr->writeln("This is an estimate of the database's disk usage. It does not represent its real size on disk.");
    }

    /**
     * Returns a query to find disk usage for a PostgreSQL database.
     *
     * @return string
     */
    private function psqlQuery()
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
     * @param array $database
     *
     * @return string
     */
    private function getPsqlCommand(array $database) {
        /** @var \Platformsh\Cli\Service\Relationships $relationships */
        $relationships = $this->getService('relationships');
        $dbUrl = $relationships->getDbCommandArgs('psql', $database, '');

        return sprintf(
            'psql --echo-hidden -t --no-align %s',
            $dbUrl
        );
    }

    private function getMongoDbCommand(array $database) {
        /** @var \Platformsh\Cli\Service\Relationships $relationships */
        $relationships = $this->getService('relationships');
        $dbUrl = $relationships->getDbCommandArgs('mongo', $database);
        
        return sprintf(
            'mongo %s --quiet --eval %s', 
            $dbUrl,
            // See https://docs.mongodb.com/manual/reference/command/dbStats/
            OsUtil::escapePosixShellArg('db.stats().fsUsedSize')
        );        
    }

    /**
     * Returns the mysql CLI client command.
     *
     * @param array  $database
     *
     * @return string
     */
    private function getMysqlCommand(array $database) {
        /** @var \Platformsh\Cli\Service\Relationships $relationships */
        $relationships = $this->getService('relationships');
        $connectionParams = $relationships->getDbCommandArgs('mysql', $database, '');

        return sprintf(
            'mysql %s --no-auto-rehash --raw --skip-column-names',
            $connectionParams
        );
    }

    /**
     * Returns a command to query table size of non-InnoDB using tables for a MySQL database in MB
     *
     * @param bool  $excludeInnoDb
     *
     * @return string
     */
    private function mysqlNonInnodbQuery($excludeInnoDb = true)
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
    private function mysqlInnodbQuery()
    {
        return 'SELECT SUM(ALLOCATED_SIZE) FROM information_schema.innodb_sys_tablespaces;';
    }

    /**
     * Returns a MySQL query to find if the InnoDB ALLOCATED_SIZE column exists.
     *
     * @return string
     */
    private function mysqlInnodbAllocatedSizeExists() {
        return 'SELECT count(COLUMN_NAME) FROM information_schema.COLUMNS WHERE table_schema ="information_schema" AND table_name="innodb_sys_tablespaces" AND column_name LIKE "ALLOCATED_SIZE";';
    }

    /**
     * Returns a MySQL query to find InnoDB tables needing optimization.
     *
     * @return string
     */
    private function mysqlTablesInNeedOfOptimizing() {
        /*, data_free, data_length, ((data_free+1)/(data_length+1))*100 as wasted_space_percentage*/
        return 'SELECT TABLE_SCHEMA, TABLE_NAME FROM information_schema.tables WHERE ENGINE = "InnoDB" AND TABLE_TYPE="BASE TABLE" AND ((data_free+1)/(data_length+1))*100 >= '.self::WASTED_SPACE_WARNING_THRESHOLD.' ORDER BY data_free DESC LIMIT 10';
    }

    /**
     * Estimates usage of a database.
     *
     * @param HostInterface $host
     * @param array         $database
     *
     * @return int Estimated usage in bytes.
     */
    private function getEstimatedUsage(HostInterface $host, array $database) {
        switch($database['scheme']) {
            case 'pgsql': 
                return $this->getPgSqlUsage($host, $database); 
            case 'mongodb': 
                return $this->getMongoDbUsage($host, $database);
            default: 
                return $this->getMySqlUsage($host, $database);
        }
    }

    /**
     * Estimates usage of a PostgreSQL database.
     *
     * @param HostInterface $host
     * @param array         $database
     *
     * @return int Estimated usage in bytes
     */
    private function getPgSqlUsage(HostInterface $host, array $database) {
        return (float) $host->runCommand($this->getPsqlCommand($database), true, true, $this->psqlQuery());
    }

    private function getMongoDbUsage(HostInterface $host, array $database) {
        return (float) $host->runCommand($this->getMongoDbCommand($database));
    }

    /**
     * Estimates usage of a MySQL database.
     *
     * @param HostInterface $host
     * @param array         $database
     *
     * @return int Estimated usage in bytes
     */
    private function getMySqlUsage(HostInterface $host, array $database) {
        $this->debug('Getting MySQL usage...');
        $allocatedSizeSupported = $host->runCommand($this->getMysqlCommand($database), true, true, $this->mysqlInnodbAllocatedSizeExists());
        $innoDbSize = 0;
        if ($allocatedSizeSupported) {
            $this->debug('Checking InnoDB separately for more accurate results...');
            try {
                $innoDbSize = $host->runCommand($this->getMysqlCommand($database), true, true, $this->mysqlInnodbQuery());
            } catch (\Symfony\Component\Process\Exception\RuntimeException $e) {
                // Some configurations do not have PROCESS privilege(s) and thus have no access to the sys_tablespaces
                // table. Ignore MySQL's 1227 Access Denied error, and revert to the legacy calculation.
                if (stripos($e->getMessage(), 'access denied') !== false) {
                    $this->debug('InnoDB checks not available: ' . $e->getMessage());
                    $allocatedSizeSupported = false;
                } else {
                    throw $e;
                }
            }
        }

        $otherSizes = $host->runCommand($this->getMysqlCommand($database), true, true, $this->mysqlNonInnodbQuery((bool) $allocatedSizeSupported));

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
    private function formatPercentage($percentage, $machineReadable) {
        if ($machineReadable) {
            return round($percentage);
        }

        if ($percentage > self::RED_WARNING_THRESHOLD) {
            $format = '<options=bold;fg=red>~ %d%%</>';
        } elseif ($percentage > self::YELLOW_WARNING_THRESHOLD) {
            $format = '<options=bold;fg=yellow>~ %d%%</>';
        } else {
            $format = '<options=bold;fg=green>~ %d%%</>';
        }

        return sprintf($format, round($percentage));
    }
}
