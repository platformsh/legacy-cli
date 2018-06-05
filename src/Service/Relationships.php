<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Util\OsUtil;
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
    public function configureInput(InputDefinition $definition): void
    {
        $definition->addOption(
            new InputOption('relationship', 'r', InputOption::VALUE_REQUIRED, 'The service relationship to use')
        );
    }

    /**
     * Choose a database for the user.
     *
     * @param string          $sshUrl
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return array|false
     */
    public function chooseDatabase($sshUrl, InputInterface $input, OutputInterface $output)
    {
        return $this->chooseService($sshUrl, $input, $output, ['mysql', 'pgsql']);
    }

    /**
     * Choose a service for the user.
     *
     * @param string          $sshUrl
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @param string[]        $schemes Filter by scheme.
     *
     * @return array|false
     */
    public function chooseService($sshUrl, InputInterface $input, OutputInterface $output, $schemes = [])
    {
        $stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $relationships = $this->getRelationships($sshUrl);

        // Filter to find services matching the schemes.
        if (!empty($schemes)) {
            $relationships = array_filter($relationships, function (array $relationship) use ($schemes) {
                foreach ($relationship as $key => $service) {
                    if (isset($service['scheme']) && in_array($service['scheme'], $schemes, true)) {
                        return true;
                    }
                }

                return false;
            });
        }

        if (empty($relationships)) {
            $stdErr->writeln(sprintf('No relationships found matching scheme(s): <error>%s</error>.', implode(', ', $schemes)));
            return false;
        }

        // Use the --relationship option, if specified.
        if ($input->hasOption('relationship')
            && ($relationshipName = $input->getOption('relationship'))) {
            if (!isset($relationships[$relationshipName])) {
                $stdErr->writeln('Relationship not found: ' . $relationshipName);
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
        $choice = $questionHelper->choose($choices, 'Enter a number to choose a relationship:');
        list($name, $key) = explode($separator, $choice, 2);
        $service = $relationships[$name][$key];

        // Add metadata about the service.
        $service['_relationship_name'] = $name;
        $service['_relationship_key'] = $key;

        return $service;
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
    public function getDbCommandArgs($command, array $database)
    {
        switch ($command) {
            case 'psql':
            case 'pg_dump':
                return OsUtil::escapePosixShellArg(sprintf(
                    'postgresql://%s:%s@%s:%d/%s',
                    $database['username'],
                    $database['password'],
                    $database['host'],
                    $database['port'],
                    $database['path']
                ));

            case 'mysql':
            case 'mysqldump':
                return sprintf(
                    '--user=%s --password=%s --host=%s --port=%d %s',
                    OsUtil::escapePosixShellArg($database['username']),
                    OsUtil::escapePosixShellArg($database['password']),
                    OsUtil::escapePosixShellArg($database['host']),
                    $database['port'],
                    OsUtil::escapePosixShellArg($database['path'])
                );

            case 'mongo':
            case 'mongodump':
            case 'mongoexport':
            case 'mongorestore':
                $args = sprintf(
                    '--username %s --password %s --host %s --port %d --authenticationDatabase %s',
                    OsUtil::escapePosixShellArg($database['username']),
                    OsUtil::escapePosixShellArg($database['password']),
                    OsUtil::escapePosixShellArg($database['host']),
                    $database['port'],
                    OsUtil::escapePosixShellArg($database['path'])
                );
                if ($command === 'mongo') {
                    $args .= ' ' . OsUtil::escapePosixShellArg($database['path']);
                } else {
                    $args .= ' --db ' . OsUtil::escapePosixShellArg($database['path']);
                }

                return $args;
        }

        throw new \InvalidArgumentException('Unrecognised command: ' . $command);
    }

    /**
     * @param string $sshUrl
     */
    public function clearCaches($sshUrl)
    {
        $this->envVarService->clearCaches($sshUrl);
    }
}
