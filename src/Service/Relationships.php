<?php

namespace Platformsh\Cli\Service;

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
            new InputOption('relationship', 'r', InputOption::VALUE_REQUIRED, 'The database relationship to use')
        );
    }

    /**
     * @param string          $sshUrl
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return array|false
     */
    public function chooseDatabase($sshUrl, InputInterface $input, OutputInterface $output)
    {
        $stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
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

        $questionHelper = new QuestionHelper($input, $output);
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
        $value = $this->envVarService->getEnvVar('RELATIONSHIPS', $sshUrl, $refresh);

        return json_decode(base64_decode($value), true) ?: [];
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
                $arguments = "'postgresql://%s:%s@%s:%d/%s'";
                break;

            case 'mysql':
            case 'mysqldump':
                $arguments = "'--user=%s' '--password=%s' '--host=%s' --port=%d '%s'";
                break;

            default:
                throw new \InvalidArgumentException('Unrecognised command: ' . $command);
        }

        return sprintf(
            $arguments,
            $database['username'],
            $database['password'],
            $database['host'],
            $database['port'],
            $database['path']
        );
    }
}
