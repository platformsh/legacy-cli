<?php

namespace Platformsh\Cli\Command\Db;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\Table;
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
        $this->validateInput($input);
        $appName = $this->selectApp($input);

        $sshUrl = $this->getSelectedEnvironment()->getSshUrl($appName);

        /** @var \Platformsh\Cli\Service\Relationships $relationships */
        $relationships = $this->getService('relationships');
        $database = $relationships->chooseDatabase($sshUrl, $input, $output);
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
        $allocatedDisk  = $service->disk * self::BYTE_TO_MEGABYTE;
        $estimatedUsage = $this->getEstimatedUsage($sshUrl, $database);
        $percentageUsed = round($estimatedUsage['__TOTAL__'] * 100 / $allocatedDisk);

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        $machineReadable = $table->formatIsMachineReadable();
        $showInBytes = $input->getOption('bytes') || $machineReadable;

        $columns  = ['max' => 'Allocated disk', 'used' => 'Estimated usage', 'percent_used' => 'Percentage used'];
        $this->stdErr->writeln('');
        $table->render([[
            'max' => $showInBytes ? $allocatedDisk : Helper::formatMemory($allocatedDisk),
            'used' => $showInBytes ? $estimatedUsage['__TOTAL__'] : Helper::formatMemory($estimatedUsage['__TOTAL__']),
            'percent_used' => $this->formatPercentage($percentageUsed, $machineReadable),
        ]], $columns);

        $this->showWarnings($percentageUsed);



        $db_table   = $this->getService('table');
        $db_columns = ['db' => 'Database', 'used' => 'Estimated usage', 'percent_used' => 'Percentage used'];
        $db_values  = [];
        
        foreach($estimatedUsage as $db=>$size) {
            if($db=='__TOTAL__') { 
                $db_values[] = new \Symfony\Component\Console\Helper\TableSeparator();
            }
            $db_values[] = [
                'db' => $db==$database['path'] ? sprintf('<options=bold>%s</>',$db) : $db,
                'used' => $showInBytes ? size : Helper::formatMemory($size),
                'percent_used' => $db=='__TOTAL__' ? '' : $this->formatPercentage(round($size * 100 / $estimatedUsage['__TOTAL__']), $machineReadable),
            ];
            
        }
        if(count($db_values)) {
            $db_table->render($db_values, $db_columns);
        } else {
            $this->showInaccessibleSchemas($service, $database);//we don't need to do this for InnoDB mysql. We can access the data we need.
        }        
        

        if ($database['scheme'] !== 'pgsql' && $estimatedUsage > 0 && $input->getOption('cleanup')) {
            $this->checkInnoDbTablesInNeedOfOptimizing($sshUrl, $database);
        }

        return 0;
    }

    private function showEstimatedUsage() {
        
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
     * @param string $sshUrl
     * @param array  $database
     *
     * @return void
     */
    private function checkInnoDbTablesInNeedOfOptimizing($sshUrl, array $database) {
        $tablesNeedingCleanup = explode(PHP_EOL, $this->runSshCommand($sshUrl, $this->mysqlTablesInNeedOfOptimizing($database)));
        $queries = $this->getCleanupQueries($tablesNeedingCleanup);

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
            foreach ($queries as $query) {
                $this->stdErr->write($query);
                $this->runSshCommand($sshUrl, $this->getMysqlCommand($database, $query));
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
     * Returns a command to query disk usage for a PostgreSQL database.
     *
     * @param array $database The database connection details.
     *
     * @return string
     */
    private function psqlQuery(array $database)
    {
        //both these queries are wrong... 
        //$query = 'SELECT SUM(pg_database_size(t1.datname)) as size FROM pg_database t1'; //does miss lots of data
        //$query = 'SELECT SUM(pg_total_relation_size(pg_class.oid)) AS size FROM pg_class LEFT OUTER JOIN pg_namespace ON (pg_namespace.oid = pg_class.relnamespace)';

        //but running both, and taking the average, gets us closer to the correct value
        $query = 'SELECT AVG(size) FROM (SELECT SUM(pg_database_size(t1.datname)) as size FROM pg_database t1 UNION SELECT SUM(pg_total_relation_size(pg_class.oid)) AS size FROM pg_class LEFT OUTER JOIN pg_namespace ON (pg_namespace.oid = pg_class.relnamespace)) x;';//too much

        /** @var \Platformsh\Cli\Service\Relationships $relationships */
        $relationships = $this->getService('relationships');
        $dbUrl = $relationships->getDbCommandArgs('psql', $database, '');

        return sprintf(
            "psql --echo-hidden -t --no-align %s -c '%s'",
            $dbUrl,
            $query
        );
    }

    /**
     * Returns the mysql CLI client command for an SQL query.
     *
     * @param array  $database
     * @param string $query
     *
     * @return string
     */
    private function getMysqlCommand(array $database, $query) {
        /** @var \Platformsh\Cli\Service\Relationships $relationships */
        $relationships = $this->getService('relationships');
        $connectionParams = $relationships->getDbCommandArgs('mysql', $database, '');

        return sprintf(
            "mysql %s --no-auto-rehash --raw --skip-column-names --execute '%s'",
            $connectionParams,
            $query
        );
    }

    /**
     * Returns a command to query table size of non-InnoDB using tables for a MySQL database in MB
     *
     * @param array $database The database connection details.
     * @param bool  $excludeInnoDb
     *
     * @return string
     */
    private function mysqlNonInnodbQuery(array $database, $excludeInnoDb = true)
    {
        $query = 'SELECT'
            . ' ('
            . 'SUM(data_length+index_length+data_free)'
            . ' + (COUNT(*) * 300 * 1024)'
            . ')'
            . ' AS estimated_actual_disk_usage'
            . ' FROM information_schema.tables'
            . ($excludeInnoDb ? ' WHERE ENGINE <> "InnoDB"' : '');
        
        return $this->getMysqlCommand($database, $query);
    }

    /**
     * Returns a MySQL query to find disk usage for all InnoDB tables.
     *
     * @param array $database The database connection details.
     *
     * @return string
     */
    private function mysqlInnodbQuery(array $database)
    {
        $deployment = $this->api()->getCurrentDeployment($this->getSelectedEnvironment());
        $service    = $deployment->getService($database['service']);
        $databases  = array_keys($service->configuration['endpoints'][$database['rel']]['privileges']);
        
        //convert database names to OR statements that the sys_tablespaces can use.
        $strDbs     = implode(' OR ', array_map(
                                        function($database) {
                                            return "`NAME` LIKE \"$database/%\"";
                                        },$databases)
        );   
        
        $query      = 'SELECT LEFT(`NAME`, LOCATE("/",`NAME`)-1), SUM(ALLOCATED_SIZE) FROM information_schema.innodb_sys_tablespaces GROUP BY LEFT(`NAME`, LOCATE("/",`NAME`)-1);';
        return $this->getMysqlCommand($database, $query);
    }

    /**
     * Returns a MySQL query to find if the InnoDB ALLOCATED_SIZE column exists.
     *
     * @param array $database
     *
     * @return string
     */
    private function mysqlInnodbAllocatedSizeExists(array $database) {
        $query = 'SELECT count(COLUMN_NAME) FROM information_schema.COLUMNS WHERE table_schema ="information_schema" AND table_name="innodb_sys_tablespaces" AND column_name LIKE "ALLOCATED_SIZE";';

        return $this->getMysqlCommand($database, $query);
    }

    /**
     * Returns a MySQL query to find InnoDB tables needing optimization.
     *
     * @param array $database The database connection details.
     *
     * @return string
     */
    private function mysqlTablesInNeedOfOptimizing(array $database) {
        /*, data_free, data_length, ((data_free+1)/(data_length+1))*100 as wasted_space_percentage*/ 
        $query = 'SELECT TABLE_SCHEMA, TABLE_NAME FROM information_schema.tables WHERE ENGINE = "InnoDB" AND ((data_free+1)/(data_length+1))*100 >= '.self::WASTED_SPACE_WARNING_THRESHOLD.' ORDER BY data_free DESC LIMIT 10';
        
        return $this->getMysqlCommand($database, $query);
    }

    /**
     * Estimates usage of a database.
     *
     * @param string $sshUrl
     * @param array  $database
     *
     * @return array ['__TOTAL__'=>0,...]
     */
    private function getEstimatedUsage($sshUrl, array $database) {
        if ($database['scheme'] === 'pgsql') {
            return ['__TOTAL__'=>$this->getPgSqlUsage($sshUrl, $database)];
        }

        return $this->getMySqlUsage($sshUrl, $database);
    }

    /**
     * Estimates usage of a PostgreSQL database.
     *
     * @param string $sshUrl
     * @param array  $database
     *
     * @return int Estimated usage in bytes
     */
    private function getPgSqlUsage($sshUrl, array $database) {
        return (int) $this->runSshCommand($sshUrl, $this->psqlQuery($database));
    }

    /**
     * Estimates usage of a MySQL database.
     *
     * @param string $sshUrl
     * @param array  $database
     *
     * @return int Estimated usage in bytes
     */
    private function getMySqlUsage($sshUrl, array $database) {
        $this->debug('Getting MySQL usage...');
        $isAllocatedSizeSupported= $this->runSshCommand($sshUrl, $this->mysqlInnodbAllocatedSizeExists($database));        
        $otherSizes              = $this->runSshCommand($sshUrl, $this->mysqlNonInnodbQuery($database, (bool) $isAllocatedSizeSupported));
        $innoDbSizes            =[];
            
        if ($isAllocatedSizeSupported) {
            $this->debug('Checking InnoDB separately for more accurate results...');
            foreach(explode(PHP_EOL,$this->runSshCommand($sshUrl, $this->mysqlInnodbQuery($database))) as $row) {
                list($dbName, $dbSize) = explode("\t", $row);
                $innoDbSizes[$dbName] = $dbSize;
            }            
        }

        return $innoDbSizes + ['__OTHER__'=>$otherSizes, '__TOTAL__'=>array_sum($innoDbSizes)+$otherSizes];
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

    /**
     * Runs a command over SSH.
     *
     * @param string $sshUrl
     * @param string $command
     *
     * @return string
     */
    private function runSshCommand($sshUrl, $command) {
        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');
        /** @var \Platformsh\Cli\Service\Ssh $ssh */
        $ssh = $this->getService('ssh');
        $args = array_merge(
            ['ssh'],
            $ssh->getSshArgs(),
            [
                $sshUrl,
                $command
            ]
        );

        return $shell->execute($args, null, true);
    }

}
