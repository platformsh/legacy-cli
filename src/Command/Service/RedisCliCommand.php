<?php

namespace Platformsh\Cli\Command\Service;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\Host\RemoteHost;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'service:redis-cli', description: 'Access the Redis CLI', aliases: ['redis'])]
class RedisCliCommand extends CommandBase
{
    public function __construct(private readonly Relationships $relationships)
    {
        parent::__construct();
    }
    protected function configure()
    {
        $this->addArgument('args', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Arguments to add to the Redis command');
        Relationships::configureInput($this->getDefinition());
        Ssh::configureInput($this->getDefinition());
        $this->addProjectOption()
            ->addEnvironmentOption()
            ->addAppOption();
        $this->addExample('Open the redis-cli shell');
        $this->addExample('Ping the Redis server', 'ping');
        $this->addExample('Show Redis status information', 'info');
        $this->addExample('Scan keys', "-- --scan");
        $this->addExample('Scan keys matching a pattern', '-- "--scan --pattern \'*-11*\'"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->runningViaMulti && !$input->getArgument('args')) {
            throw new \RuntimeException('The redis-cli command cannot run as a shell via multi');
        }

        $relationshipsService = $this->relationships;
        $host = $this->selectHost($input, $relationshipsService->hasLocalEnvVar());

        $service = $relationshipsService->chooseService($host, $input, $output, ['redis']);
        if (!$service) {
            return 1;
        }

        $redisCommand = sprintf(
            'redis-cli -h %s -p %d',
            OsUtil::escapePosixShellArg($service['host']),
            $service['port']
        );
        if ($args = $input->getArgument('args')) {
            if (count($args) === 1) {
                $redisCommand .= ' ' . $args[0];
            } else {
                $redisCommand .= ' ' . implode(' ', array_map([OsUtil::class, 'escapePosixShellArg'], $args));
            }
        } elseif ($this->isTerminal(STDIN) && $host instanceof RemoteHost) {
            // Force TTY output when the input is a terminal.
            $host->setExtraSshOptions(['RequestTTY yes']);
        }

        $this->stdErr->writeln(
            sprintf('Connecting to Redis service via relationship <info>%s</info> on <info>%s</info>', $service['_relationship_name'], $host->getLabel())
        );

        return $host->runCommandDirect($redisCommand);
    }
}
