<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Db;

use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\Host\RemoteHost;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db:sql', description: 'Run SQL on the remote database', aliases: ['sql'])]
class DbSqlCommand extends CommandBase
{
    public function __construct(private readonly Api $api, private readonly Io $io, private readonly QuestionHelper $questionHelper, private readonly Relationships $relationships, private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this
            ->addArgument('query', InputArgument::OPTIONAL, 'An SQL statement to execute')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Produce raw, non-tabular output');
        $this->addOption('schema', null, InputOption::VALUE_REQUIRED, 'The schema to use. Omit to use the default schema (usually "main"). Pass an empty string to not use any schema.');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->selector->addAppOption($this->getDefinition());
        $this->addCompleter($this->selector);
        Relationships::configureInput($this->getDefinition());
        Ssh::configureInput($this->getDefinition());
        $this->addExample('Open an SQL console on the remote database');
        $this->addExample('View tables on the remote database', "'SHOW TABLES'");
        $this->addExample('Import a dump file into the remote database', '< dump.sql');
        $this->setHiddenAliases(['environment:sql']);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getArgument('query') && $this->runningViaMulti) {
            throw new InvalidArgumentException('The query argument is required when running via "multi"');
        }

        $selectorConfig = new SelectorConfig(
            envRequired: false,
            allowLocalHost: $this->relationships->hasLocalEnvVar(),
            chooseEnvFilter: SelectorConfig::filterEnvsMaybeActive(),
        );
        // TODO check if this still allows offline use from the container
        $selection = $this->selector->getSelection($input, $selectorConfig);
        $host = $this->selector->getHostFromSelection($input, $selection);

        $database = $this->relationships->chooseDatabase($host, $input, $output);
        if (empty($database)) {
            return 1;
        }

        $schema = $input->getOption('schema');
        if ($schema === null) {
            if ($selection->hasEnvironment()) {
                // Get information about the deployed service associated with the
                // selected relationship.
                $deployment = $this->api->getCurrentDeployment($selection->getEnvironment());
                $service = isset($database['service']) ? $deployment->getService($database['service']) : false;
            } else {
                $service = false;
            }

            // Get a list of schemas (database names) from the service configuration.
            $schemas = $service ? $this->relationships->getServiceSchemas($service) : [];

            // Filter the list by the schemas accessible from the endpoint.
            if (isset($database['rel'])
                && $service
                && isset($service->configuration['endpoints'][$database['rel']]['privileges'])) {
                $schemas = array_intersect(
                    $schemas,
                    array_keys($service->configuration['endpoints'][$database['rel']]['privileges']),
                );
            }

            // If the database path is not in the list of schemas, we have to
            // use that.
            if (!empty($database['path']) && !in_array($database['path'], $schemas, true)) {
                $schema = $database['path'];
            } elseif (count($schemas) === 1) {
                $schema = reset($schemas);
            } else {
                // Provide the user with a choice of schemas.
                $choices = [];
                $schemas[] = '(none)';
                $default = ($database['path'] ?: '(none)');
                foreach ($schemas as $schema) {
                    $choices[$schema] = $schema;
                    if ($schema === $default) {
                        $choices[$schema] .= ' (default)';
                    }
                }
                $schema = $this->questionHelper->choose($choices, 'Enter a number to choose a schema:', $default, true);
                $schema = $schema === '(none)' ? '' : $schema;
            }
        }

        $query = $input->getArgument('query');

        switch ($database['scheme']) {
            case 'pgsql':
                $sqlCommand = 'psql ' . $this->relationships->getDbCommandArgs('psql', $database, $schema);
                if ($query) {
                    if ($input->getOption('raw')) {
                        $sqlCommand .= ' -t';
                    }
                    $sqlCommand .= ' -c ' . OsUtil::escapePosixShellArg($query);
                }
                break;

            default:
                $cmdName = $this->relationships->isMariaDB($database) ? 'mariadb' : 'mysql';
                $cmdInvocation = $this->relationships->mariaDbCommandWithFallback($cmdName);
                $sqlCommand = $cmdInvocation . ' --no-auto-rehash ' . $this->relationships->getDbCommandArgs($cmdName, $database, $schema);
                if ($query) {
                    if ($input->getOption('raw')) {
                        $sqlCommand .= ' --batch --raw';
                    }
                    $sqlCommand .= ' --execute ' . OsUtil::escapePosixShellArg($query);
                }
                break;
        }

        // Enable tabular output when the input is a terminal.
        if (!$input->getOption('raw') && $host instanceof RemoteHost && $this->io->isTerminal(STDIN)) {
            $host->setExtraSshOptions(['RequestTTY yes']);
        }

        return $host->runCommandDirect($sqlCommand);
    }
}
