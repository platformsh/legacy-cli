<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use GuzzleHttp\Psr7\Query;
use GuzzleHttp\Psr7\Uri;
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
    /**
     * @param RemoteEnvVars $envVarService
     */
    public function __construct(protected RemoteEnvVars $envVarService) {}

    /**
     * @param InputDefinition $definition
     */
    public static function configureInput(InputDefinition $definition): void
    {
        $definition->addOption(
            new InputOption('relationship', 'r', InputOption::VALUE_REQUIRED, 'The service relationship to use'),
        );
    }

    /**
     * Choose a database for the user.
     *
     * @param string[] $types A service type filter.
     *
     * @return false|array<string, mixed>
     */
    public function chooseDatabase(HostInterface $host, InputInterface $input, OutputInterface $output, array $types = ['mysql', 'pgsql']): false|array
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
     * @return false|array{
     *     scheme: string,
     *     username: string,
     *     password: string,
     *     host: string,
     *     port:int,
     *     path: string,
     *     _relationship_name: string,
     *     _relationship_key: string,
     *  }
     */
    public function chooseService(HostInterface $host, InputInterface $input, OutputInterface $output, array $schemes = []): array|false
    {
        $stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $relationships = $this->getRelationships($host);

        // Filter to find services matching the schemes.
        if (!empty($schemes)) {
            $relationships = array_filter($relationships, function (array $relationship) use ($schemes): bool {
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
                $stdErr->writeln('No relationships found');
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
            if (str_ends_with((string) $relationshipName, '.0')
                && isset($relationships[$relationshipName]) && count($relationships[$relationshipName]) === 1) {
                $relationshipName = substr((string) $relationshipName, 0, strlen((string) $relationshipName) - 2);
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

        if (str_contains((string) $identifier, '.')) {
            [$name, $key] = explode('.', (string) $identifier, 2);
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
                [$serviceName, ] = explode(':', $appConfig['relationships'][$name], 2);
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
     * @return array<string, mixed>
     */
    public function getRelationships(HostInterface $host, bool $refresh = false): array
    {
        return $this->normalizeRelationships(
            $this->envVarService->getArrayEnvVar('RELATIONSHIPS', $host, $refresh),
        );
    }

    /**
     * Normalizes relationships that have weird output in the API.
     *
     * If only real-life relationships were this simple.
     *
     * @param array<string, mixed> $relationships
     *
     * @return array<string, mixed>
     */
    private function normalizeRelationships(array $relationships): array
    {
        foreach ($relationships as &$relationship) {
            foreach ($relationship as &$instance) {
                // If there is a "host" which is actually a full MongoDB
                // multi-instance URI such a mongodb://hostname1,hostname2,hostname3:1234/path?query
                // then this converts it into a valid URL (selecting just the
                // first hostname), and parses that to populate the instance
                // definition.
                if (isset($instance['scheme']) && isset($instance['host'])
                    && $instance['scheme'] === 'mongodb' && str_starts_with((string) $instance['host'], 'mongodb://')) {
                    $mongodbUri = $instance['host'];
                    $url = \preg_replace_callback('#^(mongodb://)([^/?]+)([/?]|$)#', fn($matches): string => $matches[1] . \explode(',', (string) $matches[2])[0] . $matches[3], (string) $mongodbUri);
                    $urlParts = \parse_url((string) $url);
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
     * @param array<string, mixed> $database The database definition from the relationships.
     * @return bool
     */
    public function isMariaDB(array $database): bool
    {
        return isset($database['type']) && (str_starts_with((string) $database['type'], 'mariadb:') || str_starts_with((string) $database['type'], 'mysql:'));
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
    public function mariaDbCommandWithFallback(string $cmd): string
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
     * @param string $command
     *   The command that will need arguments (one of 'psql', 'pg_dump',
     *  'mysql', mysqldump', 'mariadb' or 'mariadb-dump').
     * @param array<string, mixed> $database
     *   The database definition from the relationship.
     * @param string|null $schema
     *   The name of a database schema, null to use the default schema, or an
     *    empty string to not select a schema.
     *
     * @return string
     *   The command line arguments (excluding the $command).
     */
    public function getDbCommandArgs(string $command, array $database, ?string $schema = null): string
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
                    $database['port'],
                );
                if ($schema !== '') {
                    $url .= '/' . rawurlencode((string) $schema);
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
                    $database['port'],
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
                    $database['port'],
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
    public function hasLocalEnvVar(): bool
    {
        return $this->envVarService->getEnvVar('RELATIONSHIPS', new LocalHost()) !== '';
    }

    /**
     * Builds a URL from the parts included in a relationship array.
     *
     * @param array<string, mixed> $instance
     *
     * @return string
     */
    public function buildUrl(array $instance): string
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
            $parts['query'] = Query::build($parts['query']);
        }

        // Special case #1: Solr.
        if (isset($parts['scheme']) && $parts['scheme'] === 'solr') {
            $parts['scheme'] = 'http';
            if (isset($parts['path']) && \dirname((string) $parts['path']) === '/solr') {
                $parts['path'] = '/solr/';
            }
        }
        // Special case #2: PostgreSQL.
        if (isset($parts['scheme']) && $parts['scheme'] === 'pgsql') {
            $parts['scheme'] = 'postgresql';
        }

        return Uri::fromParts($parts)->__toString();
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
     * See https://docs.upsun.com/anchors/fixed/services/postgresql/
     * and https://docs.upsun.com/anchors/fixed/services/mysql/
     *
     * @return string[]
     */
    public function getServiceSchemas(Service $service): array
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
