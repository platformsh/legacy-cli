<?php
namespace Platformsh\Cli\Command\Db;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DbSqlCommand extends CommandBase
{

    protected function configure()
    {
        $this->setName('db:sql')
            ->setAliases(['sql'])
            ->setDescription('Run SQL on the remote database')
            ->addArgument('query', InputArgument::OPTIONAL, 'An SQL statement to execute')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Produce raw, non-tabular output');
        $this->addOption('schema', null, InputOption::VALUE_REQUIRED, 'The schema to dump. Omit to use the default schema (usually "main"). Pass an empty string to not use any schema.');
        $this->addProjectOption()->addEnvironmentOption()->addAppOption();
        Relationships::configureInput($this->getDefinition());
        Ssh::configureInput($this->getDefinition());
        $this->addExample('Open an SQL console on the remote database');
        $this->addExample('View tables on the remote database', "'SHOW TABLES'");
        $this->addExample('Import a dump file into the remote database', '< dump.sql');
        $this->setHiddenAliases(['environment:sql']);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        if (!$input->getArgument('query') && $this->runningViaMulti) {
            throw new InvalidArgumentException('The query argument is required when running via "multi"');
        }

        $sshUrl = $this->getSelectedEnvironment()
                       ->getSshUrl($this->selectApp($input));

        /** @var \Platformsh\Cli\Service\Relationships $relationships */
        $relationships = $this->getService('relationships');
        $database = $relationships->chooseDatabase($sshUrl, $input, $output);
        if (empty($database)) {
            return 1;
        }

        $schema = $input->getOption('schema');
        if ($schema === null) {
            // Get information about the deployed service associated with the
            // selected relationship.
            $deployment = $this->api()->getCurrentDeployment($this->getSelectedEnvironment());
            $service = $deployment->getService($database['service']);

            // Get a list of schemas from the service configuration.
            $schemas = !empty($service->configuration['schemas'])
                ? $service->configuration['schemas']
                : ['main'];

            // Filter the list by the schemas accessible from the endpoint.
            if (isset($database['rel'])
                && isset($service->configuration['endpoints'][$database['rel']]['privileges'])) {
                $schemas = array_intersect(
                    $schemas,
                    array_keys($service->configuration['endpoints'][$database['rel']]['privileges'])
                );
            }

            // If the database path is not in the list of schemas, we have to
            // use that - it probably indicates an integrated Enterprise
            // environment.
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
                /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
                $questionHelper = $this->getService('question_helper');
                $schema = $questionHelper->choose($choices, 'Enter a number to choose a schema:', $default . ' (default)', true);
                $schema = $schema === '(none)' ? '' : $schema;
            }
        }

        $query = $input->getArgument('query');

        switch ($database['scheme']) {
            case 'pgsql':
                $sqlCommand = 'psql ' . $relationships->getDbCommandArgs('psql', $database, $schema);
                if ($query) {
                    if ($input->getOption('raw')) {
                        $sqlCommand .= ' -t';
                    }
                    $sqlCommand .= ' -c ' . OsUtil::escapePosixShellArg($query);
                }
                break;

            default:
                $sqlCommand = 'mysql --no-auto-rehash ' . $relationships->getDbCommandArgs('mysql', $database, $schema);
                if ($query) {
                    if ($input->getOption('raw')) {
                        $sqlCommand .= ' --batch --raw';
                    }
                    $sqlCommand .= ' --execute ' . OsUtil::escapePosixShellArg($query);
                }
                break;
        }

        /** @var \Platformsh\Cli\Service\Ssh $ssh */
        $ssh = $this->getService('ssh');

        $sshOptions = [];
        $sshCommand = $ssh->getSshCommand($sshOptions);
        if ($this->isTerminal(STDIN)) {
            $sshCommand .= ' -t';
        }
        $sshCommand .= ' ' . escapeshellarg($sshUrl)
            . ' ' . escapeshellarg($sqlCommand);

        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');

        return $shell->executeSimple($sshCommand);
    }
}
