<?php

namespace Platformsh\Cli\Command\Service\MongoDB;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'service:mongo:restore', description: 'Restore a binary archive dump of data into MongoDB', aliases: ['mongorestore'])]
class MongoRestoreCommand extends CommandBase
{
    public function __construct(private readonly Relationships $relationships, private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure()
    {
        $this->addOption('collection', 'c', InputOption::VALUE_REQUIRED, 'The collection to restore');
        Relationships::configureInput($this->getDefinition());
        Ssh::configureInput($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition())
            ->addAppOption($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $streams = [STDIN];
        if (!stream_select($streams, $write, $except, 0)) {
            throw new InvalidArgumentException('This command requires a mongodump archive to be piped into STDIN');
        }

        $relationshipsService = $this->relationships;
        $host = $this->selectHost($input, $relationshipsService->hasLocalEnvVar());

        $service = $relationshipsService->chooseService($host, $input, $output, ['mongodb']);
        if (!$service) {
            return 1;
        }

        $command = 'mongorestore ' . $relationshipsService->getDbCommandArgs('mongorestore', $service);

        if ($input->getOption('collection')) {
            $command .= ' --collection ' . OsUtil::escapePosixShellArg($input->getOption('collection'));
        }

        $command .= ' --archive';

        if ($output->isDebug()) {
            $command .= ' --verbose';
        }

        set_time_limit(0);

        return $host->runCommandDirect($command);
    }
}
