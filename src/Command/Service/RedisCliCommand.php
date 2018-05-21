<?php

namespace Platformsh\Cli\Command\Service;

use Platformsh\Cli\Command\CommandBase;
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
        $this->setHidden(true);
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
        $this->validateInput($input);
        if ($this->runningViaMulti && !$input->getArgument('args')) {
            throw new \RuntimeException('The redis-cli command cannot run as a shell via multi');
        }

        $sshUrl = $this->getSelectedEnvironment()
            ->getSshUrl($this->selectApp($input));

        /** @var \Platformsh\Cli\Service\Relationships $relationshipsService */
        $relationshipsService = $this->getService('relationships');
        $service = $relationshipsService->chooseService($sshUrl, $input, $output, ['redis']);
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

        /** @var \Platformsh\Cli\Service\Ssh $ssh */
        $ssh = $this->getService('ssh');

        $sshOptions = [];
        $sshCommand = $ssh->getSshCommand($sshOptions);
        if ($this->isTerminal(STDIN)) {
            $sshCommand .= ' -t';
        }
        $sshCommand .= ' ' . escapeshellarg($sshUrl)
            . ' ' . escapeshellarg($redisCommand);

        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');

        $this->stdErr->writeln(
            sprintf('Connecting to Redis service via relationship <info>%s</info> on <info>%s</info>', $service['_relationship_name'], $sshUrl)
        );

        return $shell->executeSimple($sshCommand);
    }
}
