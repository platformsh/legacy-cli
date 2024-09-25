<?php

namespace Platformsh\Cli\Service;

use GuzzleHttp\Query;
use Platformsh\Cli\Model\Host\HostInterface;
use Platformsh\Cli\Model\Host\LocalHost;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Client\Model\Deployment\Service;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Relationships implements InputConfiguringInterface
{

    protected $envVarService;

    /**
     * @param RemoteEnvVars $envVarService
     */
    public function __construct(RemoteEnvVars $envVarService)
    {
        $this->envVarService = $envVarService;
    }

    /**
     * @param \Symfony\Component\Console\Input\InputDefinition $definition
     */
    public static function configureInput(InputDefinition $definition)
    {
        $definition->addOption(
            new InputOption('relationship', 'r', InputOption::VALUE_REQUIRED, 'The service relationship to use')
        );
    }

    /**
     * Choose a database for the user.
     *
     * @param HostInterface $host
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return array|false
     */
    public function chooseDatabase(HostInterface $host, InputInterface $input, OutputInterface $output, $types  = ['mysql', 'pgsql'])
    {
        return $this->chooseService($host, $input, $output, $types);
    }

    /**
     * Choose a service for the user.
     *
     * @param HostInterface   $host
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @param string[]        $schemes Filter by scheme.
     *
     * @return array|false
     */
    public function chooseService(HostInterface $host, InputInterface $input, OutputInterface $output, $schemes = [])
    {
        $stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $relationships = $this->getRelationships($host);

        // Filter to find services matching the schemes.
        if (!empty($schemes)) {
            $relationships = array_filter($relationships, function (array $relationship) use ($schemes) {
                foreach ($relationship as $service) {
                    if (isset($service['scheme']) && in_array($service['scheme'], $schemes, true)) {
                        return true;
                    }
                }

                return false;
            });
        }

        if (empty($relationships)) {
            if (!empty($schemes)) {
                $stdErr->writeln(sprintf('No relationships found matching scheme(s): <error>%s</error>.', implode(', ', $schemes)));
            } else {
                $stdErr->writeln(sprintf('No relationships found'));
            }
            return false;
        }

        // Collapse relationships and services into a flat list.
        $choices = [];
        foreach ($relationships as $name => $relationship) {
            $serviceCount = count($relationship);
            foreach ($relationship as $key => $info) {
                $identifier = $name . ($serviceCount > 1 ? '.' . $key : '');
                if (isset($info['username']) && (!isset($info['host']) || $info['host'] === '127.0.0.1')) {
                    $choices[$identifier] = sprintf('%s (%s)', $identifier, $info['username']);
                } elseif (isset($info['username'], $info['host'])) {
                    $choices[$identifier] = sprintf('%s (%s@%s)', $identifier, $info['username'], $info['host']);
                } else {
                    $choices[$identifier] = $identifier;
                }
            }
        }

        // Use the --relationship option, if specified.
        $identifier = false;
        if ($input->hasOption('relationship')
            && ($relationshipName = $input->getOption('relationship'))) {
            // Normalise the relationship name to remove a trailing ".0".
            if (substr($relationshipName, -2) === '.0'
                && isset($relationships[$relationshipName]) && count($relationships[$relationshipName]) ===1) {
                $relationshipName = substr($relationshipName, 0, strlen($relationshipName) - 2);
            }
            if (!isset($choices[$relationshipName])) {
                $stdErr->writeln('Relationship not found: <error>' . $relationshipName . '</error>');
                return false;
            }
            $identifier = $relationshipName;
        }

        if (!$identifier && count($choices) === 1) {
            $identifier = key($choices);
        }

        if (!$identifier && !$input->isInteractive()) {
            $stdErr->writeln('More than one relationship found.');
            if ($input->hasOption('relationship')) {
                $stdErr->writeln('Use the <error>--relationship</error> (-r) option to specify a relationship. Options:');
                foreach (array_keys($choices) as $identifier) {
                    $stdErr->writeln('    ' . $identifier);
                }
            }
            return false;
        }

        if (!$identifier) {
            $questionHelper = new QuestionHelper($input, $output);
            $identifier = $questionHelper->choose($choices, 'Enter a number to choose a relationship:');
        }

        if (strpos($identifier, '.') !== false) {
            list($name, $key) = explode('.', $identifier, 2);
        } else {
            $name = $identifier;
            $key = 0;
        }
        $relationship = $relationships[$name][$key];

        // Ensure the service name is included in the relationship info.
        // This is for backwards compatibility with projects that do not have
        // this information.
        if (!isset($relationship['service'])) {
            $appConfig = $this->envVarService->getArrayEnvVar('APPLICATION', $host);
            if (!empty($appConfig['relationships'][$name]) && is_string($appConfig['relationships'][$name])) {
                list($serviceName, ) = explode(':', $appConfig['relationships'][$name], 2);
                $relationship['service'] = $serviceName;
            }
        }

        // Add metadata about the service.
        $relationship['_relationship_name'] = $name;
        $relationship['_relationship_key'] = $key;

        return $relationship;
    }

    /**
     * Get the relationships deployed on the application.
     *
     * @param HostInterface $host
     * @param bool          $refresh
     *
     * @return array
     */
    public function getRelationships(HostInterface $host, $refresh = false)
    {
        return $this->normalizeRelationships(
            $this->envVarService->getArrayEnvVar('RELATIONSHIPS', $host, $refresh)
        );
    }

    /**
     * Normalizes relationships that have weird output in the API.
     *
     * If only real-life relationships were this simple.
     *
     * @param array $relationships
     *
     * @return array
     */
    private function normalizeRelationships(array $relationships)
    {
        foreach ($relationships as &$relationship) {
            foreach ($relationship as &$instance) {
                // If there is a "host" which is actually a full MongoDB
                // multi-instance URI such a mongodb://hostname1,hostname2,hostname3:1234/path?query
                // then this converts it into a valid URL (selecting just the
                // first hostname), and parses that to populate the instance
                // definition.
                if (isset($instance['scheme']) && isset($instance['host'])
                    && $instance['scheme'] === 'mongodb' && strpos($instance['host'], 'mongodb://') === 0) {
                    $mongodbUri = $instance['host'];
                    $url = \preg_replace_callback('#^(mongodb://)([^/?]+)([/?]|$)#', function ($matches) {
                        return $matches[1] . \explode(',', $matches[2])[0] . $matches[3];
                    }, $mongodbUri);
                    $urlParts = \parse_url($url);
                    if ($urlParts) {
                        $instance = array_merge($urlParts, $instance);
                        // Fix the "host" to be a hostname.
                        $instance['host'] = $urlParts['host'];
                        // Set the "url" as the original "host".
                        $instance['url'] = $mongodbUri;
                    }
                }
            }
        }
        return $relationships;
    }

    /**
     * Returns whether the database is MariaDB.
     *
     * @param array $database The database definition from the relationships.
     * @return bool
     */
    public function isMariaDB(array $database)
    {
        return isset($database['type']) && (\strpos($database['type'], 'mariadb:') === 0 || \strpos($database['type'], 'mysql:') === 0);
    }

    /**
     * Returns the correct command to use with a MariaDB client.
     *
     * MariaDB now needs MariaDB-specific command names. But these were added
     * in the MariaDB client 10.4.6, and we cannot efficiently check the client
     * version, at least not before we are already running the command.
     * See: https://jira.mariadb.org/browse/MDEV-21303
     *
     * @param string $cmd
     *
     * @return string
     */
    public function mariaDbCommandWithFallback($cmd)
    {
        if ($cmd === 'mariadb') {
            return 'cmd="$(command -v mariadb || echo -n mysql)"; "$cmd"';
        }
        if ($cmd === 'mariadb-dump') {
            return 'cmd="$(command -v mariadb-dump || echo -n mysqldump)"; "$cmd"';
        }
        return $cmd;
    }

    /**
     * Returns command-line arguments to connect to a database.
     *
     * @param string      $command        The command that will need arguments
     *                                    (one of 'psql', 'pg_dump', 'mysql',
     *                                    'mysqldump', 'mariadb' or
     *                                    'mariadb-dump').
     * @param array       $database       The database definition from the
     *                                    relationship.
     * @param string|null $schema         The name of a database schema, or
     *                                    null to use the default schema, or
     *                                    an empty string to not select a
     *                                    schema.
     *
     * @return string
     *   The command line arguments (excluding the $command).
     */
    public function getDbCommandArgs($command, array $database, $schema = null)
    {
        if ($schema === null) {
            $schema = $database['path'];
        }

        switch ($command) {
            case 'psql':
            case 'pg_dump':
                $url = sprintf(
                    'postgresql://%s:%s@%s:%d',
                    $database['username'],
                    $database['password'],
                    $database['host'],
                    $database['port']
                );
                if ($schema !== '') {
                    $url .= '/' . rawurlencode($schema);
                }

                return OsUtil::escapePosixShellArg($url);

            case 'mariadb':
            case 'mariadb-dump':
            case 'mysql':
            case 'mysqldump':
                $args = sprintf(
                    '--user=%s --password=%s --host=%s --port=%d',
                    OsUtil::escapePosixShellArg($database['username']),
                    OsUtil::escapePosixShellArg($database['password']),
                    OsUtil::escapePosixShellArg($database['host']),
                    $database['port']
                );
                if ($schema !== '') {
                    $args .= ' ' . OsUtil::escapePosixShellArg($schema);
                }

                return $args;

            case 'mongo':
            case 'mongodump':
            case 'mongoexport':
            case 'mongorestore':
                if (isset($database['url'])) {
                    if ($command === 'mongo') {
                        return $database['url'];
                    }
                    return sprintf('--uri %s', OsUtil::escapePosixShellArg($database['url']));
                }
                $args = sprintf(
                    '--username %s --password %s --host %s --port %d',
                    OsUtil::escapePosixShellArg($database['username']),
                    OsUtil::escapePosixShellArg($database['password']),
                    OsUtil::escapePosixShellArg($database['host']),
                    $database['port']
                );
                if ($schema !== '') {
                    $args .= ' --authenticationDatabase ' . OsUtil::escapePosixShellArg($schema);
                    if ($command === 'mongo') {
                        $args .= ' ' . OsUtil::escapePosixShellArg($schema);
                    } else {
                        $args .= ' --db ' . OsUtil::escapePosixShellArg($schema);
                    }
                }

                return $args;
        }

        throw new \InvalidArgumentException('Unrecognised command: ' . $command);
    }

    /**
     * @return bool
     */
    public function hasLocalEnvVar()
    {
        return $this->envVarService->getEnvVar('RELATIONSHIPS', new LocalHost()) !== '';
    }

    /**
     * Builds a URL from the parts included in a relationship array.
     *
     * @param array $instance
     *
     * @return string
     */
    public function buildUrl(array $instance)
    {
        $parts = $instance;

        // Convert to parse_url parts.
        if (isset($parts['username'])) {
            $parts['user'] = $parts['username'];
        }
        if (isset($parts['password'])) {
            $parts['pass'] = $parts['password'];
        }
        unset($parts['username'], $parts['password']);
        // The 'query' is expected to be a string.
        if (isset($parts['query']) && is_array($parts['query'])) {
            unset($parts['query']['is_master']);
            $parts['query'] = (new Query($parts['query']))->__toString();
        }

        // Special case #1: Solr.
        if (isset($parts['scheme']) && $parts['scheme'] === 'solr') {
            $parts['scheme'] = 'http';
            if (isset($parts['path']) && \dirname($parts['path']) === '/solr') {
                $parts['path'] = '/solr/';
            }
        }
        // Special case #2: PostgreSQL.
        if (isset($parts['scheme']) && $parts['scheme'] === 'pgsql') {
            $parts['scheme'] = 'postgresql';
        }

        return \GuzzleHttp\Url::buildUrl($parts);
    }

    /**
     * Returns a list of schemas (database names/paths) for a service.
     *
     * The MySQL, MariaDB and Oracle MySQL services allow specifying custom
     * schemas. The PostgreSQL service has the same feature, but they are
     * unfortunately named differently: "databases" not "schemas". If nothing
     * is configured, all four service types default to having one schema named
     * "main".
     *
     * See https://docs.platform.sh/add-services/postgresql.html
     * and https://docs.platform.sh/add-services/mysql.html
     *
     * @return string[]
     */
    public function getServiceSchemas(Service $service)
    {
        if (!empty($service->configuration['schemas'])) {
            return $service->configuration['schemas'];
        }
        if (!empty($service->configuration['databases'])) {
            return $service->configuration['databases'];
        }
        if (preg_match('/^(postgresql|mariadb|mysql|oracle-mysql):/', $service->type) === 1) {
            return ['main'];
        }
        return [];
    }
}
