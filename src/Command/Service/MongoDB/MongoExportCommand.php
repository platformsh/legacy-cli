<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Service\MongoDB;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\Host\HostInterface;
use Platformsh\Cli\Model\Host\RemoteHost;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'service:mongo:export', description: 'Export data from MongoDB', aliases: ['mongoexport'])]
class MongoExportCommand extends CommandBase
{
    public function __construct(private readonly QuestionHelper $questionHelper, private readonly Relationships $relationships, private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->addOption('collection', 'c', InputOption::VALUE_REQUIRED, 'The collection to export');
        $this->addOption('jsonArray', null, InputOption::VALUE_NONE, 'Export data as a single JSON array');
        $this->addOption('type', null, InputOption::VALUE_REQUIRED, 'The export type, e.g. "csv"');
        $this->addOption('fields', 'f', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'The fields to export');
        Relationships::configureInput($this->getDefinition());
        Ssh::configureInput($this->getDefinition());
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->selector->addAppOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->addExample('Export a CSV from the "users" collection', '-c users --type csv -f name,email');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('type') === 'csv' && !$input->getOption('fields')) {
            throw new InvalidArgumentException(
                'CSV mode requires a field list.'
                . "\n" . 'Use --fields (-f) to specify field(s) to export.',
            );
        }
        $selection = $this->selector->getSelection($input, new SelectorConfig(
            allowLocalHost: $this->relationships->hasLocalEnvVar(),
            chooseEnvFilter: SelectorConfig::filterEnvsMaybeActive(),
        ));
        $host = $this->selector->getHostFromSelection($input, $selection);

        $service = $this->relationships->chooseService($host, $input, $output, ['mongodb']);
        if (!$service) {
            return 1;
        }

        if (!$collection = $input->getOption('collection')) {
            if (!$input->isInteractive()) {
                throw new InvalidArgumentException('No collection specified. Use the --collection (-c) option to specify one.');
            }
            $this->stdErr->writeln('Finding available collections... (you can skip this with the <comment>--collection</comment> option)', OutputInterface::VERBOSITY_VERBOSE);
            $collections = $this->getCollections($service, $host);
            if (empty($collections)) {
                throw new InvalidArgumentException('No collections found. You can specify one with the --collection (-c) option.');
            }
            $collection = $this->questionHelper->choose(array_combine($collections, $collections), 'Enter a number to choose a collection:', null, false);
        }

        $command = 'mongoexport ' . $this->relationships->getDbCommandArgs('mongoexport', $service);
        $command .= ' --collection ' . OsUtil::escapePosixShellArg($collection);

        if ($input->getOption('type')) {
            $command .= ' --type ' . OsUtil::escapePosixShellArg($input->getOption('type'));
        }
        if ($input->getOption('jsonArray')) {
            $command .= ' --jsonArray';
        }
        if ($input->getOption('fields')) {
            $command .= ' --fields ' . OsUtil::escapePosixShellArg(implode(',', $input->getOption('fields')));
        }

        if (!$output->isVerbose()) {
            $command .= ' --quiet';
            if ($host instanceof RemoteHost) {
                $host->setExtraSshOptions(['LogLevel QUIET']);
            }
        } elseif ($output->isDebug()) {
            $command .= ' --verbose';
        }

        return $host->runCommandDirect($command);
    }

    /**
     * Get collections in the MongoDB database.
     *
     * @param array{username: string, password: string, host: string, port:int, path: string} $service
     * @param HostInterface $host
     *
     * @return string[]
     */
    private function getCollections(array $service, HostInterface $host): array
    {
        $js = 'printjson(db.getCollectionNames())';

        $command = 'mongo '
            . $this->relationships->getDbCommandArgs('mongo', $service)
            . ' --quiet --eval ' . OsUtil::escapePosixShellArg($js)
            . ' 2>/dev/null';

        $result = $host->runCommand($command);
        if (!is_string($result)) {
            return [];
        }

        // Handle log messages that mongo prints to stdout.
        // https://jira.mongodb.org/browse/SERVER-23810
        // Hopefully the end of the output is a JavaScript array.
        if (str_ends_with($result, ']') && !str_starts_with(trim($result), '[') && ($openPos = strrpos($result, "\n[")) !== false) {
            $result = substr($result, $openPos);
        }

        $collections = json_decode($result, true) ?: [];

        return array_filter($collections, fn(string $collection): bool => !str_starts_with((string) $collection, 'system.'));
    }
}
