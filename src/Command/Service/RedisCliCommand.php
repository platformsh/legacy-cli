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

class RedisCliCommand extends CommandBase
{
    protected function configure()
    {
        $this->setName('service:redis-cli');
        $this->setAliases(['redis']);
        $this->setDescription('Access the Redis CLI');
        $this->addArgument('args', InputArgument::OPTIONAL, 'Arguments to add to the Redis command');
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

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($this->runningViaMulti && !$input->getArgument('args')) {
            throw new \RuntimeException('The redis-cli command cannot run as a shell via multi');
        }

        /** @var \Platformsh\Cli\Service\Relationships $relationshipsService */
        $relationshipsService = $this->getService('relationships');
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
            $redisCommand .= ' ' . $args;
        }

        $this->stdErr->writeln(
            sprintf('Connecting to Redis service via relationship <info>%s</info> on <info>%s</info>', $service['_relationship_name'], $host->getLabel())
        );

        if ($this->isTerminal(STDIN) && $host instanceof RemoteHost) {
            $host->setExtraSshArgs(['-t']);
        }

        return $host->runCommandDirect($redisCommand);
    }
}
