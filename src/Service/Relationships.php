<?php

namespace Platformsh\Cli\Service;

use Doctrine\Common\Cache\CacheProvider;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

class Relationships implements InputConfiguringInterface
{

    protected $output;
    protected $shellHelper;
    protected $config;
    protected $ssh;
    protected $cache;

    /**
     * @param OutputInterface $output
     * @param Ssh             $ssh
     * @param CacheProvider   $cache
     * @param Shell           $shellHelper
     * @param Config          $config
     */
    public function __construct(
        OutputInterface $output,
        Ssh $ssh,
        CacheProvider $cache,
        Shell $shellHelper,
        Config $config
    ) {
        $this->output = $output;
        $this->ssh = $ssh;
        $this->cache = $cache;
        $this->shellHelper = $shellHelper;
        $this->config = $config;
    }

    /**
     * @param \Symfony\Component\Console\Input\InputDefinition $definition
     */
    public static function configureInput(InputDefinition $definition)
    {
        $definition->addOption(
            new InputOption('relationship', 'r', InputOption::VALUE_REQUIRED, 'The database relationship to use')
        );
    }

    /**
     * @param string          $sshUrl
     * @param InputInterface  $input
     *
     * @return array|false
     */
    public function chooseDatabase($sshUrl, InputInterface $input)
    {
        $stdErr = $this->output instanceof ConsoleOutput ? $this->output->getErrorOutput() : $this->output;
        $relationships = $this->getRelationships($sshUrl);

        // Filter to find database (mysql and pgsql) relationships.
        $relationships = array_filter($relationships, function (array $relationship) {
            foreach ($relationship as $key => $service) {
                if ($service['scheme'] === 'mysql' || $service['scheme'] === 'pgsql') {
                    return true;
                }
            }

            return false;
        });

        if (empty($relationships)) {
            $stdErr->writeln('No databases found');
            return false;
        }

        // Use the --relationship option, if specified.
        if ($input->hasOption('relationship')
            && ($relationshipName = $input->getOption('relationship'))) {
            if (!isset($relationships[$relationshipName])) {
                $stdErr->writeln('Database relationship not found: ' . $relationshipName);
                return false;
            }
            $relationships = array_intersect_key($relationships, [$relationshipName => true]);
        }

        $questionHelper = new QuestionHelper($input, $this->output);
        $choices = [];
        $separator = '.';
        foreach ($relationships as $name => $relationship) {
            $serviceCount = count($relationship);
            foreach ($relationship as $key => $service) {
                $choices[$name . $separator . $key] = $name . ($serviceCount > 1 ? '.' . $key : '');
            }
        }
        $choice = $questionHelper->choose($choices, 'Enter a number to choose a database:');
        list($name, $key) = explode($separator, $choice, 2);
        $database = $relationships[$name][$key];

        // Add metadata about the database.
        $database['_relationship_name'] = $name;
        $database['_relationship_key'] = $key;

        return $database;
    }

    /**
     * @param string $sshUrl
     * @param bool   $refresh
     *
     * @return array
     */
    public function getRelationships($sshUrl, $refresh = false)
    {
        $cacheKey = 'relationships-' . $sshUrl;
        $relationships = $this->cache->fetch($cacheKey);
        if ($refresh || empty($relationships)) {
            $args = ['ssh'];
            if (isset($this->ssh)) {
                $args = array_merge($args, $this->ssh->getSshArgs());
            }
            $args[] = $sshUrl;
            $args[] = 'echo $' . $this->config->get('service.env_prefix') . 'RELATIONSHIPS';
            $result = $this->shellHelper->execute($args, null, true);
            $relationships = json_decode(base64_decode($result), true);
            $this->cache->save($cacheKey, $relationships, 3600);
        }

        return $relationships;
    }

    /**
     * Clear the cache.
     *
     * @param string $sshUrl
     */
    public function clearCache($sshUrl)
    {
        $this->cache->delete('relationships-' . $sshUrl);
    }

    /**
     * Returns command-line arguments to connect to a database.
     *
     * @param string $command  The command that will need arguments (one of
     *                         'psql', 'pg_dump', 'mysql', or 'mysqldump').
     * @param array  $database The database definition from the relationship.
     *
     * @return string
     *   The command line arguments (excluding the $command).
     */
    public function getSqlCommandArgs($command, array $database)
    {
        switch ($command) {
            case 'psql':
            case 'pg_dump':
                return sprintf(
                    "'postgresql://%s:%s@%s:%d/%s'",
                    $database['username'],
                    $database['password'],
                    $database['host'],
                    $database['port'],
                    $database['path']
                );

            case 'mysql':
            case 'mysqldump':
                return sprintf(
                    "'--user=%s' '--password=%s' '--host=%s' --port=%d '%s'",
                    $database['username'],
                    $database['password'],
                    $database['host'],
                    $database['port'],
                    $database['path']
                );

            default:
                throw new \InvalidArgumentException('Unrecognised command: ' . $command);
        }
    }
}
