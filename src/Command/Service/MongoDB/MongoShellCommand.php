<?php

namespace Platformsh\Cli\Command\Service\MongoDB;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\Host\RemoteHost;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MongoShellCommand extends CommandBase
{
    protected function configure()
    {
        $this->setName('service:mongo:shell');
        $this->setAliases(['mongo']);
        $this->setDescription('Use the MongoDB shell');
        $this->addOption('eval', null, InputOption::VALUE_REQUIRED, 'Pass a JavaScript fragment to the shell');
        Relationships::configureInput($this->getDefinition());
        Ssh::configureInput($this->getDefinition());
        $this->addProjectOption()
            ->addEnvironmentOption()
            ->addAppOption();
        $this->addExample('Display collection names', "--eval 'printjson(db.getCollectionNames())'");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($this->runningViaMulti) {
            throw new \RuntimeException('The mongo-shell command cannot run via multi');
        }

        /** @var \Platformsh\Cli\Service\Relationships $relationshipsService */
        $relationshipsService = $this->getService('relationships');
        $host = $this->selectHost($input, $relationshipsService->hasLocalEnvVar());

        $service = $relationshipsService->chooseService($host, $input, $output, ['mongodb']);
        if (!$service) {
            return 1;
        }

        $command = 'mongo ' . $relationshipsService->getDbCommandArgs('mongo', $service);

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

        if ($this->isTerminal(STDIN) && $host instanceof RemoteHost) {
            $host->setExtraSshArgs(['-t']);
        }

        $this->stdErr->writeln(
            sprintf('Connecting to MongoDB service via relationship <info>%s</info> on <info>%s</info>', $service['_relationship_name'], $host->getLabel()),
            OutputInterface::VERBOSITY_VERBOSE
        );

        return $host->runCommandDirect($command);
    }
}
