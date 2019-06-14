<?php
namespace Platformsh\Cli\Command\Db;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\ApiFeatureMissingException;
use Platformsh\Cli\Model\AppConfig;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Util\YamlParser;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Platformsh\Cli\Local\BuildFlavor\Symfony;
use Symfony\Component\Console\Helper\Helper;

class DbSizeCommand extends CommandBase
{
    
    const RED_WARNING_THRESHOLD=90;
    const YELLOW_WARNING_THRESHOLD=80;
    const BYTE_TO_MBYTE=1048576;

    protected function configure()
    {
        $this->setName('db:size')
            ->setDescription('Estimate the disk usage of a database')
            ->setHelp(
                "This is an estimate of the database disk usage. The real size on disk is usually a bit higher because of overhead."
            );
        $this->addProjectOption()->addEnvironmentOption()->addAppOption();
        Relationships::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition());
        Ssh::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $appName = $this->selectApp($input);

        // Get the app config.
        $webApp = $this->api()
            ->getCurrentDeployment($this->getSelectedEnvironment(), true)
            ->getWebApp($appName);
        $appConfig = AppConfig::fromWebApp($webApp)->getNormalized();
        if (empty($appConfig['relationships'])) {
            $this->stdErr->writeln('No application relationships found.');
            return 1;
        }
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

        $this->showEstimatedUsageTable($service, $database, $appName);
        $this->showInaccessibleSchemas($service, $database);
        
        return 0;
    }

    private function showEstimatedUsageTable($service, $database, $appName) {
        $allocatedDisk  = $service->disk;//in MB
        $estimatedUsage = $this->getEstimatedUsage($appName, $database);//in MB
        

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        $machineReadable = $table->formatIsMachineReadable();

        if ($allocatedDisk !== false) {
            $propertyNames  = ['Allocated', 'Estimated Usage', 'Percentage Used'];
            $percentageUsed = $estimatedUsage * 100 / $allocatedDisk;
            $values = [
                $this->formatMegaBytes($allocatedDisk,$machineReadable),
                $this->formatMegaBytes($estimatedUsage,$machineReadable),
                ' ~ '. $this->formatPercentage($percentageUsed),                
            ];
        } else {
            $percentageUsed = null;
            $propertyNames = ['Estimated Usage'];
            $values = [
                $this->formatMegaBytes($estimatedUsage,$machineReadable),
            ];
        }
        
        $this->stdErr->writeln('');
        $table->renderSimple($values, $propertyNames);

        $this->showWarnings($percentageUsed);
    }

    private function showInaccessibleSchemas($service, $database) {
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

    private function showWarnings($percentageUsed) {
        if($percentageUsed > self::RED_WARNING_THRESHOLD) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('<options=bold;fg=red>Warning</>');
            $this->stdErr->writeln("Databases tend to need a little bit of extra space for starting up and temporary storage when running large queries. Please increase the allocated space in services.yaml");    
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
        // I couldn't find a way to run the SUM directly in the database query...
        $query = 'SELECT'
          . ' sum(pg_relation_size(pg_class.oid))::bigint AS size'
          . ' FROM pg_class'
          . ' LEFT OUTER JOIN pg_namespace ON (pg_namespace.oid = pg_class.relnamespace)'
          . ' GROUP BY pg_class.relkind, nspname'
          . ' ORDER BY sum(pg_relation_size(pg_class.oid)) DESC;';

        /** @var \Platformsh\Cli\Service\Relationships $relationships */
        $relationships = $this->getService('relationships');
        $dbUrl = $relationships->getDbCommandArgs('psql', $database, '');

        return sprintf(
            "psql --echo-hidden -t --no-align %s -c '%s'",
            $dbUrl,
            $query
        );
    }

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
     *
     * @return string
     */
    private function mysqlNonInnodbQuery(array $database)
    {
        $query = 'SELECT'
            . ' ('
            . 'SUM(data_length+index_length+data_free)'
            . ' + (COUNT(*) * 300 * 1024)'
            . ')'
            . '/' . (self::BYTE_TO_MBYTE + 150) . ' AS estimated_actual_disk_usage'
            . ' FROM information_schema.tables WHERE ENGINE <> "InnoDB"';
        return $this->getMysqlCommand($database, $query);
    }

    /**
     * Returns a command to query disk usage for all InnoDB using tables for a MySQL database in MB
     *
     * @param array $database The database connection details.
     *
     * @return string
     */
    private function mysqlInnodbQuery(array $database)
    {
        $query = 'SELECT SUM(ALLOCATED_SIZE) / '.self::BYTE_TO_MBYTE.' FROM information_schema.innodb_sys_tablespaces;';

        return $this->getMysqlCommand($database, $query);
    }

    private function getEstimatedUsage($appName, $database) {
        switch ($database['scheme']) {
            case 'pgsql':
                return $this->getPgSqlUsage($appName, $database);
            
            case 'mysql':
            default:
                return $this->getMySqlUsage($appName, $database);
        }
    }

    private function getPgSqlUsage($appName, $database) {
        return  array_sum(
                    explode(PHP_EOL, 
                        $this->runSshCommand($appName, $this->psqlQuery($database))
                    )
                );
    }

    private function getMySqlUsage($appName, $database) {
        return array_sum(
            [
                $this->runSshCommand($appName, $this->mysqlNonInnodbQuery($database)),
                $this->runSshCommand($appName, $this->mysqlInnodbQuery($database))
            ]
        );
    }
    
    private function formatPercentage($percentage) {
        switch(true) {
            case $percentage > self::RED_WARNING_THRESHOLD:
                return sprintf("<options=bold;fg=red>%d %%</>",(int) $percentage);
            break;
            case $percentage > self::YELLOW_WARNING_THRESHOLD:
                return sprintf("<options=bold;fg=yellow>%d %%</>",(int) $percentage);
            break;
            default:
                return sprintf("<options=bold;fg=green>%d %%</>",(int) $percentage);
        }        
    }

    private function formatMegaBytes($intMBytes, $hasToBeMachineReadable=false) {
        return $hasToBeMachineReadable ? floor($intMBytes)     : $this->toHumanReadableBytes($intMBytes);
    }
    
    private function toHumanReadableBytes($intMBytes, $intDecimals=2) {
        return Helper::formatMemory(round($intMBytes*self::BYTE_TO_MBYTE));        
    }
    
    private function runSshCommand($appName, $strCommandToExec) {
        return $this->getService('shell')
                    ->execute(
                        array_merge(
                            ['ssh'], 
                            $this->getService('ssh')->getSshArgs(),
                            [
                                $this->getSelectedEnvironment()->getSshUrl($appName),
                                $strCommandToExec
                            ]
                        ), 
                        null, true
                    );
    }
    
}
