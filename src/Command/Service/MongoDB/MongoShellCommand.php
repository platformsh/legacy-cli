<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Service\MongoDB;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\Host\RemoteHost;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'service:mongo:shell', description: 'Use the MongoDB shell', aliases: ['mongo'])]
class MongoShellCommand extends CommandBase
{
    public function __construct(private readonly Io $io, private readonly Relationships $relationships, private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->addOption('eval', null, InputOption::VALUE_REQUIRED, 'Pass a JavaScript fragment to the shell');
        Relationships::configureInput($this->getDefinition());
        Ssh::configureInput($this->getDefinition());
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->selector->addAppOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->addExample('Display collection names', "--eval 'printjson(db.getCollectionNames())'");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->runningViaMulti) {
            throw new \RuntimeException('The mongo-shell command cannot run via multi');
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

        $command = 'mongo ' . $this->relationships->getDbCommandArgs('mongo', $service);

        if ($input->getOption('eval')) {
            $command .= ' --eval ' . OsUtil::escapePosixShellArg($input->getOption('eval'));
        }

        if (!$output->isVerbose()) {
            $command .= ' --quiet';
        } elseif ($output->isDebug()) {
            $command .= ' --verbose';
        }

        // Force TTY output when the input is a terminal.
        if ($this->io->isTerminal(STDIN) && $host instanceof RemoteHost) {
            $host->setExtraSshOptions(['RequestTTY yes']);
        }

        $this->stdErr->writeln(
            sprintf('Connecting to MongoDB service via relationship <info>%s</info> on <info>%s</info>', $service['_relationship_name'], $host->getLabel()),
            OutputInterface::VERBOSITY_VERBOSE,
        );

        return $host->runCommandDirect($command);
    }
}
