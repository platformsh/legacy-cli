<?php

namespace Platformsh\Cli\Command\Service\MongoDB;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MongoShellCommand extends CommandBase
{
    protected static $defaultName = 'service:mongo:shell';

    private $relationships;
    private $selector;
    private $shell;
    private $ssh;

    public function __construct(
        Relationships $relationships,
        Selector $selector,
        Shell $shell,
        Ssh $ssh
    ) {
        $this->relationships = $relationships;
        $this->selector = $selector;
        $this->shell = $shell;
        $this->ssh = $ssh;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['mongo']);
        $this->setDescription('Use the MongoDB shell');
        $this->setHidden(true);
        $this->addOption('eval', null, InputOption::VALUE_REQUIRED, 'Pass a JavaScript fragment to the shell');

        $definition = $this->getDefinition();
        $this->relationships->configureInput($definition);
        $this->ssh->configureInput($definition);
        $this->selector->addAllOptions($definition);

        $this->addExample('Display collection names', "--eval 'printjson(db.getCollectionNames())'");
    }

    public function canBeRunMultipleTimes()
    {
        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);

        $sshUrl = $selection->getEnvironment()
            ->getSshUrl($selection->getAppName());

        $service = $this->relationships->chooseService($sshUrl, $input, $output, ['mongodb']);
        if (!$service) {
            return 1;
        }

        $command = 'mongo ' . $this->relationships->getDbCommandArgs('mongo', $service);

        if ($input->getOption('eval')) {
            $command .= ' --eval ' . OsUtil::escapePosixShellArg($input->getOption('eval'));
        };

        $sshOptions = [];

        if (!$output->isVerbose()) {
            $command .= ' --quiet';
            $sshOptions['LogLevel'] = 'QUIET';
        } elseif ($output->isDebug()) {
            $command .= ' --verbose';
        }

        $sshCommand = $this->ssh->getSshCommand($sshOptions);
        if ($this->isTerminal(STDIN)) {
            $sshCommand .= ' -t';
        }
        $sshCommand .= ' ' . escapeshellarg($sshUrl)
            . ' ' . escapeshellarg($command);

        $this->stdErr->writeln(
            sprintf('Connecting to MongoDB service via relationship <info>%s</info> on <info>%s</info>', $service['_relationship_name'], $sshUrl),
            OutputInterface::VERBOSITY_VERBOSE
        );

        return $this->shell->executeSimple($sshCommand);
    }
}
