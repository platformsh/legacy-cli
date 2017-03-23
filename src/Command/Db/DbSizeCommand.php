<?php
namespace Platformsh\Cli\Command\Db;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
        Relationships::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition());
        Ssh::configureInput($this->getDefinition());
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

        /** @var \Platformsh\Cli\Service\Relationships $relationships */
        $relationships = $this->getService('relationships');

        $database = $relationships->chooseDatabase($sshUrl, $input, $output);
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
        /** @var \Platformsh\Cli\Local\LocalProject $localProject */
        $localProject = $this->getService('local.project');
        $services = (array) $localProject->readProjectConfigFile($projectRoot, 'services.yaml');
        if (!empty($services[$dbServiceName]['disk'])) {
            $allocatedDisk = $services[$dbServiceName]['disk'];
        } else {
            $this->stdErr->writeln('The allocated disk size could not be determined for service: ' . $dbServiceName);
            return 1;
        }

        $this->stdErr->write('Querying database <comment>' . $dbServiceName . '</comment> to estimate disk usage. ');
        $this->stdErr->writeln('This might take a while.');

        /** @var Shell $shell */
        $shell = $this->getService('shell');
        /** @var \Platformsh\Cli\Service\Ssh $ssh */
        $ssh = $this->getService('ssh');

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

        $percentsUsed = $estimatedUsage * 100 / $allocatedDisk;

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
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

        /** @var \Platformsh\Cli\Service\Relationships $relationships */
        $relationships = $this->getService('relationships');
        $dbUrl = $relationships->getSqlCommandArgs('psql', $database);

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
        $connectionParams = $relationships->getSqlCommandArgs('mysql', $database);

        return sprintf(
            "mysql %s --no-auto-rehash --raw --skip-column-names --execute '%s'",
            $connectionParams,
            $query
        );
    }
}
