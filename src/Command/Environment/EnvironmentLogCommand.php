<?php
namespace Platformsh\Cli\Command\Environment;

use Doctrine\Common\Cache\CacheProvider;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Stecman\Component\Symfony\Console\BashCompletion\Completion\CompletionAwareInterface;
use Stecman\Component\Symfony\Console\BashCompletion\CompletionContext;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentLogCommand extends CommandBase implements CompletionAwareInterface
{
    protected static $defaultName = 'environment:logs';

    private $api;
    private $cache;
    private $config;
    private $questionHelper;
    private $selector;
    private $shell;
    private $ssh;

    public function __construct(
        Api $api,
        ActivityService $activityService,
        CacheProvider $cache,
        Config $config,
        QuestionHelper $questionHelper,
        Selector $selector,
        Shell $shell,
        Ssh $ssh
    ) {
        $this->api = $api;
        $this->cache = $cache;
        $this->config = $config;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        $this->shell = $shell;
        $this->ssh = $ssh;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['log'])
            ->setDescription("Read an environment's logs")
            ->addArgument('type', InputArgument::OPTIONAL, 'The log type, e.g. "access" or "error"')
            ->addOption('lines', null, InputOption::VALUE_REQUIRED, 'The number of lines to show', 100)
            ->addOption('tail', null, InputOption::VALUE_NONE, 'Continuously tail the log');
        $this->setHiddenAliases(['logs']);

        $definition = $this->getDefinition();
        $this->selector->addEnvironmentOption($definition);
        $this->selector->addProjectOption($definition);
        $this->ssh->configureInput($definition);

        $this->addExample('Display a choice of logs that can be read');
        $this->addExample('Read the deploy log', 'deploy');
        $this->addExample('Read the access log continuously', 'access --tail');
        $this->addExample('Read the last 500 lines of the cron log', 'cron --lines 500');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);

        if ($input->getOption('tail') && $this->runningViaMulti) {
            throw new InvalidArgumentException('The --tail option cannot be used with "multi"');
        }

        $sshUrl = $selection->getEnvironment()
            ->getSshUrl($selection->getAppName());

        // Select the log file that the user specified.
        if ($logType = $input->getArgument('type')) {
            // @todo this might need to be cleverer
            if (substr($logType, -4) === '.log') {
                $logType = substr($logType, 0, strlen($logType) - 4);
            }
            $logFilename = '/var/log/' . $logType . '.log';
        } elseif (!$input->isInteractive()) {
            $this->stdErr->writeln('No log type specified.');
            return 1;
        } else {
            // Read the list of files from the environment.
            $cacheKey = sprintf('log-files:%s', $sshUrl);
            if (!$result = $this->cache->fetch($cacheKey)) {
                $result = $this->shell->execute(['ssh', $sshUrl, 'ls -1 /var/log/*.log']);

                // Cache the list for 1 day.
                $this->cache->save($cacheKey, $result, 86400);
            }

            // Provide a fallback list of files, in case the SSH command failed.
            $defaultFiles = [
                '/var/log/access.log',
                '/var/log/error.log',
            ];
            $files = $result ? explode("\n", $result) : $defaultFiles;

            // Ask the user to choose a file.
            $files = array_combine($files, array_map(function ($file) {
                return str_replace('.log', '', basename(trim($file)));
            }, $files));
            $logFilename = $this->questionHelper->choose($files, 'Enter a number to choose a log: ');
        }

        $command = sprintf('tail -n %1$d %2$s', $input->getOption('lines'), OsUtil::escapePosixShellArg($logFilename));
        if ($input->getOption('tail')) {
            $command .= ' -f';
        }

        $this->stdErr->writeln(sprintf('Reading log file <info>%s:%s</info>', $sshUrl, $logFilename));

        $sshCommand = sprintf('ssh -C %s %s', escapeshellarg($sshUrl), escapeshellarg($command));

        return $this->shell->executeSimple($sshCommand);
    }

    /**
     * {@inheritdoc}
     */
    public function completeOptionValues($optionName, CompletionContext $context)
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function completeArgumentValues($argumentName, CompletionContext $context)
    {
        $values = [];
        if ($argumentName === 'type') {
            $values = [
                'access',
                'error',
                'cron',
                'deploy',
                'app',
            ];
        }

        return $values;
    }
}
