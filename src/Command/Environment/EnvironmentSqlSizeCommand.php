<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Helper\ShellHelper;
use Platformsh\Cli\Util\RelationshipsUtil;
use Platformsh\Cli\Util\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class EnvironmentSqlSizeCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('environment:sql-size')
            ->setAliases(['sqls'])
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

        $sshUrl = $this->getSelectedEnvironment()
            ->getSshUrl($this->selectApp($input));

        /** @var ShellHelper $shellHelper */
        $shellHelper = $this->getHelper('shell');

        // Get and parse app config.
        $args = ['ssh', $sshUrl, 'echo $' . self::$config->get('service.env_prefix') . 'APPLICATION'];
        $result = $shellHelper->execute($args, null, true);
        $appConfig = json_decode(base64_decode($result), true);
        $databaseService = $appConfig['relationships']['database'];
        list($dbServiceName, $dbServiceType) = explode(":", $databaseService);

        // Load services yaml.
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            throw new RootNotFoundException();
        }
        $services = Yaml::parse(file_get_contents($projectRoot . '/.platform/services.yaml'));
        $allocatedDisk = $services[$dbServiceName]['disk'];

        $util = new RelationshipsUtil($this->stdErr);
        $database = $util->chooseDatabase($sshUrl, $input);
        if (empty($database)) {
            return 1;
        }

        $this->stdErr->write('Querying database <comment>' . $dbServiceName . '</comment> to estimate disk usage. ');
        $this->stdErr->writeln('This might take a while.');

        $command = ['ssh'];
        // Switch on pseudo-tty allocation when there is a local tty.
        if ($this->isTerminal($output)) {
            $command[] = '-t';
        }
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $command[] = '-vv';
        } elseif ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $command[] = '-v';
        } elseif ($output->getVerbosity() <= OutputInterface::VERBOSITY_VERBOSE) {
            $command[] = '-q';
        }
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
