<?php
namespace Platformsh\Cli\Command\Db;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Helper\ShellHelper;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Util\RelationshipsUtil;
use Platformsh\Cli\Util\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class DbSizeCommand extends CommandBase
{

    protected function configure()
    {
        $this->setName('db:size')
            ->setDescription('Estimate the disk usage of a database')
            ->setHelp(
                "This command provides an estimate of the database's disk usage. It is not guaranteed to be reliable."
            );
        $this->addProjectOption()->addEnvironmentOption()->addAppOption();
        Table::addFormatOption($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            throw new RootNotFoundException();
        }
        $appName = $this->selectApp($input);

        $sshUrl = $this->getSelectedEnvironment()
            ->getSshUrl($appName);

        // Get and parse app config.
        $app = LocalApplication::getApplication($appName, $projectRoot);
        $appConfig = $app->getConfig();
        if (empty($appConfig['relationships'])) {
            $this->stdErr->writeln('No application relationships found.');
            return 1;
        }

        $util = new RelationshipsUtil($this->stdErr);
        $database = $util->chooseDatabase($sshUrl, $input);
        if (empty($database)) {
            $this->stdErr->writeln('No database selected.');
            return 1;
        }

        // Find the database's service name in the relationships.
        $dbServiceName = false;
        foreach ($appConfig['relationships'] as $relationshipName => $relationship) {
            if ($database['_relationship_name'] === $relationshipName) {
                list($dbServiceName,) = explode(':', $relationship, 2);
                break;
            }
        }
        if (!$dbServiceName) {
            $this->stdErr->writeln('Service name not found for relationship: ' . $database['_relationship_name']);
            return 1;
        }

        // Load services yaml.
        $services = Yaml::parse(file_get_contents($projectRoot . '/.platform/services.yaml'));
        if (!empty($services[$dbServiceName]['disk'])) {
            $allocatedDisk = $services[$dbServiceName]['disk'];
        } else {
            $this->stdErr->writeln('The allocated disk size could not be determined for service: ' . $dbServiceName);
            return 1;
        }

        $this->stdErr->write('Querying database <comment>' . $dbServiceName . '</comment> to estimate disk usage. ');
        $this->stdErr->writeln('This might take a while.');

        /** @var ShellHelper $shellHelper */
        $shellHelper = $this->getHelper('shell');
        $command = ['ssh'];
        $command[] = $sshUrl;
        switch ($database['scheme']) {
            case 'pgsql':
                $command[] = $this->psqlQuery($database);
                $result = $shellHelper->execute($command);
                $resultArr = explode(PHP_EOL, $result);
                $estimatedUsage = array_sum($resultArr) / 1048576;
                break;
            default:
                $command[] = $this->mysqlQuery($database);
                $estimatedUsage = $shellHelper->execute($command);
                break;
        }

        $percentsUsed = $estimatedUsage * 100 / $allocatedDisk;

        $table = new Table($input, $output);
        $propertyNames = [
            'max',
            'used',
            'percent_used',
        ];
        $machineReadable = $table->formatIsMachineReadable();
        $values = [
            (int) $allocatedDisk . ($machineReadable ? '' : 'MB'),
            (int) $estimatedUsage . ($machineReadable ? '' : 'MB'),
            (int) $percentsUsed . '%',
        ];

        $table->renderSimple($values, $propertyNames);

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

        $dbUrl = sprintf(
            'postgresql://%s:%s@%s/%s',
            $database['username'],
            $database['password'],
            $database['host'],
            $database['path']
        );

        return sprintf(
            "psql --echo-hidden -t --no-align %s -c '%s' 2>&1",
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

        $connectionParams = sprintf(
            '--user=%s --password=%s --host=%s --database=%s --port=%d',
            $database['username'],
            $database['password'],
            $database['host'],
            $database['path'],
            $database['port']
        );

        return sprintf(
            "mysql %s --no-auto-rehash --raw --skip-column-names --execute '%s' 2>&1",
            $connectionParams,
            $query
        );
    }
}
