<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Service\MongoDB;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Stecman\Component\Symfony\Console\BashCompletion\Completion\CompletionAwareInterface;
use Stecman\Component\Symfony\Console\BashCompletion\CompletionContext;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MongoExportCommand extends CommandBase implements CompletionAwareInterface
{
    protected static $defaultName = 'service:mongo:export';

    private $questionHelper;
    private $relationships;
    private $selector;
    private $shell;
    private $ssh;

    public function __construct(
        QuestionHelper $questionHelper,
        Relationships $relationships,
        Selector $selector,
        Shell $shell,
        Ssh $ssh
    ) {
        $this->questionHelper = $questionHelper;
        $this->relationships = $relationships;
        $this->selector = $selector;
        $this->shell = $shell;
        $this->ssh = $ssh;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['mongoexport']);
        $this->setDescription('Export data from MongoDB');
        $this->addOption('collection', 'c', InputOption::VALUE_REQUIRED, 'The collection to export');
        $this->addOption('jsonArray', null, InputOption::VALUE_NONE, 'Export data as a single JSON array');
        $this->addOption('type', null, InputOption::VALUE_REQUIRED, 'The export type, e.g. "csv"');
        $this->addOption('fields', 'f', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'The fields to export');

        $definition = $this->getDefinition();
        $this->relationships->configureInput($definition);
        $this->ssh->configureInput($definition);
        $this->selector->addAllOptions($definition);

        $this->addExample('Export a CSV from the "users" collection', '-c users --type csv -f name,email');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);

        if ($input->getOption('type') === 'csv' && !$input->getOption('fields')) {
            throw new InvalidArgumentException(
                'CSV mode requires a field list.'
                . "\n" . 'Use --fields (-f) to specify field(s) to export.'
            );
        }

        $sshUrl = $selection->getEnvironment()
            ->getSshUrl($selection->getAppName());

        $service = $this->relationships->chooseService($sshUrl, $input, $output, ['mongodb']);
        if (!$service) {
            return 1;
        }

        if (!$collection = $input->getOption('collection')) {
            if (!$input->isInteractive()) {
                throw new InvalidArgumentException('No collection specified. Use the --collection (-c) option to specify one.');
            }
            $this->stdErr->writeln('Finding available collections... (you can skip this with the <comment>--collection</comment> option)', OutputInterface::VERBOSITY_VERBOSE);
            $collections = $this->getCollections($service, $sshUrl);
            if (empty($collections)) {
                throw new InvalidArgumentException('No collections found. You can specify one with the --collection (-c) option.');
            }
            $collection = $this->questionHelper
                ->choose(array_combine($collections, $collections), 'Enter a number to choose a collection:', null, false);
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

        $sshOptions = [];

        if (!$output->isVerbose()) {
            $command .= ' --quiet';
            $sshOptions['LogLevel'] = 'QUIET';
        } elseif ($output->isDebug()) {
            $command .= ' --verbose';
        }

        $sshCommand = $this->ssh->getSshCommand($sshOptions);
        $sshCommand .= ' ' . escapeshellarg($sshUrl)
            . ' ' . escapeshellarg($command);

        return $this->shell->executeSimple($sshCommand);
    }

    /**
     * Get collections in the MongoDB database.
     *
     * @param array  $service
     * @param string $sshUrl
     *
     * @return array
     */
    private function getCollections(array $service, $sshUrl)
    {
        $js = 'printjson(db.getCollectionNames())';

        $command = 'mongo '
            . $this->relationships->getDbCommandArgs('mongo', $service)
            . ' --quiet --eval ' . OsUtil::escapePosixShellArg($js);

        $sshArgs = array_merge(['ssh'], $this->ssh->getSshArgs());
        $sshArgs[] = $sshUrl;
        $sshArgs[] = $command;

        /** @noinspection PhpUnhandledExceptionInspection */
        $result = $this->shell->execute($sshArgs, null, true);
        if (!is_string($result)) {
            return [];
        }

        $collections = json_decode($result, true) ?: [];

        return array_filter($collections, function ($collection) {
            return substr($collection, 0, 7) !== 'system.';
        });
    }

    /**
     * {@inheritdoc}
     */
    public function completeOptionValues($optionName, CompletionContext $context)
    {
        if ($optionName === 'type') {
            return ['csv'];
        }

        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function completeArgumentValues($argumentName, CompletionContext $context)
    {
        return [];
    }
}
