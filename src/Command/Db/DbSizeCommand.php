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

class DbSizeCommand extends CommandBase
{

    protected function configure()
    {
        $this->setName('db:size')
            ->setDescription('Estimate the disk usage of a database')
            ->setHelp(
                "This is an estimate of the database disk usage. It does not represent its real size on disk."
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

        // Get information about the deployed service associated with the
        // selected relationship.
        $deployment = $this->api()->getCurrentDeployment($this->getSelectedEnvironment());
        $dbServiceName = $database['service'];
        $service = $deployment->getService($dbServiceName);
        $allocatedDisk = $service->disk;

        /** @var Shell $shell */
        $shell = $this->getService('shell');
        /** @var \Platformsh\Cli\Service\Ssh $ssh */
        $ssh = $this->getService('ssh');

        $this->stdErr->writeln(sprintf('Checking database service <comment>%s</comment>...', $dbServiceName));

        $command = ['ssh'];
        $command = array_merge($command, $ssh->getSshArgs());
        $command[] = $sshUrl;
        switch ($database['scheme']) {
            case 'pgsql':
                $command[] = $this->psqlQuery($database);
                $result = $shell->execute($command, null, true);
                $resultArr = explode(PHP_EOL, $result);
                $estimatedUsage = array_sum($resultArr) / 1048576;
                break;
            default:
                $command[] = $this->mysqlQuery($database);
                $estimatedUsage = $shell->execute($command, null, true);
                break;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        $machineReadable = $table->formatIsMachineReadable();

        if ($allocatedDisk !== false) {
            $propertyNames = ['max', 'used', 'percent_used'];
            $percentsUsed = $estimatedUsage * 100 / $allocatedDisk;
            $values = [
                (int) $allocatedDisk . ($machineReadable ? '' : 'MB'),
                (int) $estimatedUsage . ($machineReadable ? '' : 'MB'),
                (int) $percentsUsed . '%',
            ];
        } else {
            $propertyNames = ['used'];
            $values = [
                (int) $estimatedUsage . ($machineReadable ? '' : 'MB'),
            ];
        }

        $table->renderSimple($values, $propertyNames);

        $this->stdErr->writeln('');
        $this->stdErr->writeln('<options=bold;fg=yellow>Warning</>');
        $this->stdErr->writeln("This is an estimate of the database's disk usage. It does not represent its real size on disk.");

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

        return 0;
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

    /**
     * Returns a command to query disk usage for a MySQL database.
     *
     * @param array $database The database connection details.
     *
     * @return string
     */
    private function mysqlQuery(array $database)
    {
        $query = 'SELECT'
            . ' ('
            . 'SUM(data_length+index_length+data_free)'
            . ' + (COUNT(*) * 300 * 1024)'
            . ')'
            . '/' . (1048576 + 150) . ' AS estimated_actual_disk_usage'
            . ' FROM information_schema.tables';

        /** @var \Platformsh\Cli\Service\Relationships $relationships */
        $relationships = $this->getService('relationships');
        $connectionParams = $relationships->getDbCommandArgs('mysql', $database, '');

        return sprintf(
            "mysql %s --no-auto-rehash --raw --skip-column-names --execute '%s'",
            $connectionParams,
            $query
        );
    }
}
