<?php

namespace Platformsh\Cli\Command\Service\MongoDB;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MongoRestoreCommand extends CommandBase
{
    protected static $defaultName = 'service:mongo:restore';

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
        $this->setAliases(['mongorestore']);
        $this->setDescription('Restore a binary archive dump of data into MongoDB');
        $this->setHidden(true);
        $this->addOption('collection', 'c', InputOption::VALUE_REQUIRED, 'The collection to restore');

        $definition = $this->getDefinition();
        $this->relationships->configureInput($definition);
        $this->ssh->configureInput($definition);
        $this->selector->addAllOptions($definition);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);

        $sshUrl = $selection->getEnvironment()
            ->getSshUrl($selection->getAppName());

        $streams = [STDIN];
        if (!stream_select($streams, $write, $except, 0)) {
            throw new InvalidArgumentException('This command requires a mongodump archive to be piped into STDIN');
        }

        $service = $this->relationships->chooseService($sshUrl, $input, $output, ['mongodb']);
        if (!$service) {
            return 1;
        }

        $command = 'mongorestore ' . $this->relationships->getDbCommandArgs('mongorestore', $service);

        if ($input->getOption('collection')) {
            $command .= ' --collection ' . OsUtil::escapePosixShellArg($input->getOption('collection'));
        }

        $command .= ' --archive';

        $sshOptions = [];

        if ($output->isDebug()) {
            $command .= ' --verbose';
        }

        set_time_limit(0);

        $sshCommand = $this->ssh->getSshCommand($sshOptions);
        $sshCommand .= ' ' . escapeshellarg($sshUrl)
            . ' ' . escapeshellarg($command);

        return $this->shell->executeSimple($sshCommand);
    }
}
