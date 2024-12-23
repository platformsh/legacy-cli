<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Doctrine\Common\Cache\CacheProvider;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Cli\Util\StringUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'environment:logs', description: "Read an environment's logs", aliases: ['log'])]
class EnvironmentLogCommand extends CommandBase
{
    public function __construct(private readonly CacheProvider $cacheProvider, private readonly Io $io, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('type', InputArgument::OPTIONAL, 'The log type, e.g. "access" or "error"', null, [
                'access',
                'error',
                'cron',
                'deploy',
                'app',
            ])
            ->addOption('lines', null, InputOption::VALUE_REQUIRED, 'The number of lines to show', 100)
            ->addOption('tail', null, InputOption::VALUE_NONE, 'Continuously tail the log');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->selector->addRemoteContainerOptions($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->setHiddenAliases(['logs']);
        $this->addExample('Display a choice of logs that can be read');
        $this->addExample('Read the deploy log', 'deploy');
        $this->addExample('Read the access log continuously', 'access --tail');
        $this->addExample('Read the last 500 lines of the cron log', 'cron --lines 500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(chooseEnvFilter: SelectorConfig::filterEnvsMaybeActive()));

        if ($input->getOption('tail') && $this->runningViaMulti) {
            throw new InvalidArgumentException('The --tail option cannot be used with "multi"');
        }

        $host = $this->selector->getHostFromSelection($input, $selection);

        $logDir = '/var/log';

        // Special handling for Dedicated Generation 2 environments, for which
        // the SSH URL contains something like "ssh://1.ent-" or "1.ent-" or "ent-".
        if (preg_match('%(^|[/.])ent-[a-z0-9]%', $host->getLabel())) {
            $logDir = '/var/log/platform/"$USER"';
            $this->io->debug('Detected Dedicated environment: using log directory: ' . $logDir);
        }

        // Select the log file that the user specified.
        if ($logType = $input->getArgument('type')) {
            // @todo this might need to be cleverer
            if (str_ends_with((string) $logType, '.log')) {
                $logType = substr((string) $logType, 0, strlen((string) $logType) - 4);
            }
            $logFilename = $logDir . '/' . OsUtil::escapePosixShellArg($logType . '.log');
        } elseif (!$input->isInteractive()) {
            $this->stdErr->writeln('No log type specified.');
            return 1;
        } else {

            // Read the list of files from the environment.
            $cacheKey = sprintf('log-files:%s', $host->getCacheKey());
            $cache = $this->cacheProvider;
            if (!$result = $cache->fetch($cacheKey)) {
                $result = $host->runCommand('echo -n _BEGIN_FILE_LIST_; ls -1 ' . $logDir . '/*.log; echo -n _END_FILE_LIST_');
                if (is_string($result)) {
                    $result = trim((string) StringUtil::between($result, '_BEGIN_FILE_LIST_', '_END_FILE_LIST_'));
                }

                // Cache the list for 1 day.
                $cache->save($cacheKey, $result, 86400);
            }

            // Provide a fallback list of files, in case the SSH command failed.
            $defaultFiles = [
                $logDir . '/access.log',
                $logDir . '/error.log',
            ];
            $files = $result && is_string($result) ? explode("\n", $result) : $defaultFiles;

            // Ask the user to choose a file.
            $files = array_combine($files, array_map(fn($file): string => str_replace('.log', '', basename(trim((string) $file))), $files));
            $logFilename = $this->questionHelper->choose($files, 'Enter a number to choose a log: ');
        }

        $command = sprintf('tail -n %1$d %2$s', $input->getOption('lines'), $logFilename);
        if ($input->getOption('tail')) {
            $command .= ' -f';
        }

        $this->stdErr->writeln(sprintf('Reading log file <info>%s:%s</info>', $host->getLabel(), $logFilename));

        return $host->runCommandDirect($command);
    }
}
