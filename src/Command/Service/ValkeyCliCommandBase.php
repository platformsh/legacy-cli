<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Service;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\Host\RemoteHost;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class ValkeyCliCommandBase extends CommandBase
{
    protected string $dbName = 'valkey';
    protected string $dbTitle = 'valkey';
    protected string $dbCommand = 'valkey-cli';

    public function __construct(private readonly Io $io, private readonly Relationships $relationships, private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->addArgument('args', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, sprintf('Arguments to add to the %s command', $this->dbCommand));
        Relationships::configureInput($this->getDefinition());
        Ssh::configureInput($this->getDefinition());
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->selector->addAppOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->addExample(sprintf('Open the %s shell', $this->dbCommand));
        $this->addExample(sprintf('Ping the %s server', $this->dbTitle), 'ping');
        $this->addExample(sprintf('Show %s status information', $this->dbTitle), 'info');
        $this->addExample('Scan keys', "-- --scan");
        $this->addExample('Scan keys matching a pattern', '-- "--scan --pattern \'*-11*\'"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->runningViaMulti && !$input->getArgument('args')) {
            throw new \RuntimeException(sprintf('The %s command cannot run as a shell via multi', $this->dbCommand));
        }

        $selection = $this->selector->getSelection($input, new SelectorConfig(
            allowLocalHost: $this->relationships->hasLocalEnvVar(),
            chooseEnvFilter: SelectorConfig::filterEnvsMaybeActive(),
        ));
        $host = $this->selector->getHostFromSelection($input, $selection);

        $service = $this->relationships->chooseService($host, $input, $output, [$this->dbName]);
        if (!$service) {
            return 1;
        }

        $command = sprintf(
            '%s -h %s -p %d',
            $this->dbCommand,
            OsUtil::escapePosixShellArg($service['host']),
            $service['port'],
        );
        if ($args = $input->getArgument('args')) {
            if (count($args) === 1) {
                $command .= ' ' . $args[0];
            } else {
                $command .= ' ' . implode(' ', array_map(OsUtil::escapePosixShellArg(...), $args));
            }
        } elseif ($this->io->isTerminal(STDIN) && $host instanceof RemoteHost) {
            // Force TTY output when the input is a terminal.
            $host->setExtraSshOptions(['RequestTTY yes']);
        }

        $this->stdErr->writeln(
            sprintf('Connecting to %s service via relationship <info>%s</info> on <info>%s</info>', $this->dbTitle, $service['_relationship_name'], $host->getLabel()),
        );

        return $host->runCommandDirect($command);
    }
}
