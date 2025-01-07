<?php

namespace Platformsh\Cli\Command\Service;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\Host\RemoteHost;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ValkeyCliCommand extends CommandBase
{
    protected $dbName = 'valkey';
    protected $dbTitle = 'Valkey';
    protected $dbCommand = 'valkey-cli';

    protected function configure()
    {
        $this->setName('service:' . $this->dbCommand);
        $this->setAliases([$this->dbName]);
        $this->setDescription('Access the ' . $this->dbTitle . ' CLI');
        $this->addArgument('args', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, sprintf('Arguments to add to the %s command', $this->dbCommand));
        Relationships::configureInput($this->getDefinition());
        Ssh::configureInput($this->getDefinition());
        $this->addProjectOption()
            ->addEnvironmentOption()
            ->addAppOption();
        $this->addExample(sprintf('Open the %s shell', $this->dbCommand));
        $this->addExample(sprintf('Ping the %s server', $this->dbTitle), 'ping');
        $this->addExample(sprintf('Show %s status information', $this->dbTitle), 'info');
        $this->addExample('Scan keys', "-- --scan");
        $this->addExample('Scan keys matching a pattern', '-- "--scan --pattern \'*-11*\'"');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($this->runningViaMulti && !$input->getArgument('args')) {
            throw new \RuntimeException(sprintf('The %s command cannot run as a shell via multi', $this->dbCommand));
        }

        /** @var \Platformsh\Cli\Service\Relationships $relationshipsService */
        $relationshipsService = $this->getService('relationships');
        $host = $this->selectHost($input, $relationshipsService->hasLocalEnvVar());

        $service = $relationshipsService->chooseService($host, $input, $output, [$this->dbName]);
        if (!$service) {
            return 1;
        }

        $command = sprintf(
            '%s -h %s -p %d',
            $this->dbCommand,
            OsUtil::escapePosixShellArg($service['host']),
            $service['port']
        );
        if ($args = $input->getArgument('args')) {
            if (count($args) === 1) {
                $command .= ' ' . $args[0];
            } else {
                $command .= ' ' . implode(' ', array_map([OsUtil::class, 'escapePosixShellArg'], $args));
            }
        } elseif ($this->isTerminal(STDIN) && $host instanceof RemoteHost) {
            // Force TTY output when the input is a terminal.
            $host->setExtraSshOptions(['RequestTTY yes']);
        }

        $this->stdErr->writeln(
            sprintf('Connecting to %s service via relationship <info>%s</info> on <info>%s</info>', $this->dbTitle, $service['_relationship_name'], $host->getLabel())
        );

        return $host->runCommandDirect($command);
    }
}
