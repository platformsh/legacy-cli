<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Db;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\ApiFeatureMissingException;
use Platformsh\Cli\Model\AppConfig;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Util\YamlParser;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DbSizeCommand extends CommandBase
{

    protected static $defaultName = 'db:size';

    private $api;
    private $config;
    private $relationships;
    private $selector;
    private $shell;
    private $ssh;
    private $table;

    public function __construct(
        Api $api,
        Config $config,
        Relationships $relationships,
        Selector $selector,
        Shell $shell,
        Ssh $ssh,
        Table $table
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->relationships = $relationships;
        $this->selector = $selector;
        $this->shell = $shell;
        $this->ssh = $ssh;
        $this->table = $table;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Estimate the disk usage of a database')
            ->setHelp(
                "This is an estimate of the database disk usage. It does not represent its real size on disk."
            );

        $definition = $this->getDefinition();
        $this->selector->addAllOptions($definition);
        $this->relationships->configureInput($definition);
        $this->ssh->configureInput($definition);
        $this->table->configureInput($definition);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);
        $appName = $selection->getAppName();

        // Get the app config.
        $webApp = $this->api
            ->getCurrentDeployment($selection->getEnvironment(), true)
            ->getWebApp($appName);
        $appConfig = AppConfig::fromWebApp($webApp)->getNormalized();
        if (empty($appConfig['relationships'])) {
            $this->stdErr->writeln('No application relationships found.');
            return 1;
        }

        $sshUrl = $selection->getEnvironment()->getSshUrl($appName);

        $database = $this->relationships->chooseDatabase($sshUrl, $input, $output);
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
        $services = $this->getProjectServiceConfig($selection->getEnvironment());
        if (!empty($services[$dbServiceName]['disk'])) {
            $allocatedDisk = $services[$dbServiceName]['disk'];
        } else {
            $this->stdErr->writeln('The allocated disk size could not be determined for service: <comment>' . $dbServiceName . '</comment>');
            $allocatedDisk = false;
        }

        $this->stdErr->writeln('Checking database <comment>' . $dbServiceName . '</comment>...');

        $command = ['ssh'];
        $command = array_merge($command, $this->ssh->getSshArgs());
        $command[] = $sshUrl;
        switch ($database['scheme']) {
            case 'pgsql':
                $command[] = $this->psqlQuery($database);
                $result = $this->shell->execute($command, null, true);
                $resultArr = explode(PHP_EOL, $result);
                $estimatedUsage = array_sum($resultArr) / 1048576;
                break;
            default:
                $command[] = $this->mysqlQuery($database);
                $estimatedUsage = $this->shell->execute($command, null, true);
                break;
        }

        $machineReadable = $this->table->formatIsMachineReadable();

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

        $this->table->renderSimple($values, $propertyNames);

        $this->stdErr->writeln('');
        $this->stdErr->writeln('<options=bold;fg=yellow>Warning</>');
        $this->stdErr->writeln("This is an estimate of the database's disk usage. It does not represent its real size on disk.");

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

        $dbUrl = $this->relationships->getDbCommandArgs('psql', $database);

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

        $connectionParams = $this->relationships->getDbCommandArgs('mysql', $database);

        return sprintf(
            "mysql %s --no-auto-rehash --raw --skip-column-names --execute '%s'",
            $connectionParams,
            $query
        );
    }

    /**
     * Find the service configuration (from services.yaml).
     *
     * @param Environment $environment
     *
     * @return array
     */
    private function getProjectServiceConfig(Environment $environment)
    {
        $servicesYaml = false;
        $servicesYamlFilename = $this->config->get('service.project_config_dir') . '/services.yaml';
        $services = [];
        try {
            $servicesYaml = $this->api->readFile($servicesYamlFilename, $environment);
        } catch (ApiFeatureMissingException $e) {
            $this->debug($e->getMessage());
            if ($projectRoot = $this->selector->getProjectRoot()) {
                $this->debug('Reading file in local project: ' . $projectRoot . '/' . $servicesYamlFilename);
                $servicesYaml = file_get_contents($projectRoot . '/' . $servicesYamlFilename);
            }
        }
        if ($servicesYaml) {
            $services = (new YamlParser())->parseContent($servicesYaml, $servicesYamlFilename);
        }

        return $services;
    }
}
