<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Server;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Local\ApplicationFinder;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Service\Url;
use Platformsh\Cli\Util\PortUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\RuntimeException;

#[AsCommand(name: 'server:run', description: 'Run a local PHP web server')]
class ServerRunCommand extends ServerCommandBase
{
    public function __construct(private readonly ApplicationFinder $applicationFinder, private readonly Config $config, private readonly QuestionHelper $questionHelper, private readonly Selector $selector, private readonly Url $url)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this
          ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force starting server')
          ->addOption('app', null, InputOption::VALUE_REQUIRED, 'The app name')
          ->addOption('ip', null, InputOption::VALUE_REQUIRED, 'The IP address', '127.0.0.1')
          ->addOption('port', null, InputOption::VALUE_REQUIRED, 'The port')
          ->addOption('log', null, InputOption::VALUE_REQUIRED, 'The name of a log file (logs are written to stderr by default)');
        Url::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = $this->selector->getProjectRoot();
        if (!$projectRoot) {
            throw new RootNotFoundException();
        }

        $ip = $input->getOption('ip');
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->stdErr->writeln(sprintf('Invalid IP address: <error>%s</error>', $ip));
            return 1;
        }

        $port = $input->getOption('port') ?: $this->getPort();
        if (!PortUtil::validatePort($port)) {
            $this->stdErr->writeln(sprintf('Invalid port: <error>%s</error>', $port));
            return 1;
        }

        $finder = $this->applicationFinder;
        $apps = $finder->findApplications($projectRoot);
        if (!count($apps)) {
            $this->stdErr->writeln(sprintf('No applications found in directory: %s', $projectRoot));
            return 1;
        }

        $appId = $input->getOption('app');
        if (!$appId) {
            $appChoices = [];
            foreach ($apps as $appCandidate) {
                $appChoices[$appCandidate->getId()] = $appCandidate->getId();
            }
            $appId = $this->questionHelper->choose($appChoices, 'Enter a number to choose an app:');
        }
        foreach ($apps as $appCandidate) {
            if ($appCandidate->getId() === $appId) {
                $app = $appCandidate;
                break;
            }
        }
        if (!isset($app)) {
            $this->stdErr->writeln(sprintf('App not found: <error>%s</error>', $appId));
            return 1;
        }

        $docRoot = $app->getLocalWebRoot();
        if (!file_exists($docRoot)) {
            $this->stdErr->writeln(sprintf('Document root not found: <error>%s</error>', $docRoot));
            $this->stdErr->writeln(sprintf(
                'Build the application with: <comment>%s build</comment>',
                $this->config->getStr('application.executable'),
            ));
            return 1;
        }

        $logFile = $input->getOption('log');
        if ($logFile) {
            $log = $this->openLog($logFile);
            if (!$log) {
                $this->stdErr->writeln(sprintf('Failed to open log file for writing: <error>%s</error>', $logFile));
                return 1;
            }
        } else {
            $log = $this->stdErr;
        }

        $address = sprintf('%s:%s', $ip, $port);

        $appConfig = $app->getConfig();
        if (Drupal::isDrupal($app->getRoot())) {
            $appConfig['drupal_7_workaround'] = true;
        }

        $force = $input->getOption('force');

        if ($otherServer = $this->isServerRunningForApp($appId, $projectRoot)) {
            if (!$force) {
                $this->stdErr->writeln(sprintf(
                    'A server is already running for the app <info>%s</info> at http://%s, PID %s',
                    $appId,
                    $otherServer['address'],
                    $otherServer['pid'],
                ));
                return 1;
            }

            // If the port was not manually specified, kill the old server and
            // take its address.
            $this->stdErr->writeln(sprintf(
                'Stopping server for the app <info>%s</info> at http://%s',
                $appId,
                $otherServer['address'],
            ));
            $this->stopServer($address, $otherServer['pid']);
            sleep(1);

            // If the address was not manually specified, take the old server's
            // address.
            if (!$input->getOption('port') && $input->getOption('ip') === '127.0.0.1') {
                $address = $otherServer['address'];
            }
        }

        if ($otherPid = $this->isServerRunningForAddress($address)) {
            if (!$force || $otherPid === true) {
                $this->stdErr->writeln(sprintf(
                    'A server is already running at address: http://%s, PID %s',
                    $address,
                    $otherPid === true ? 'unknown' : $otherPid,
                ));
                return 1;
            }

            $this->stdErr->writeln(sprintf('Stopping existing server at <comment>http://%s</comment>', $address));
            $this->stopServer($address, $otherPid);
            sleep(1);
        }

        $process = $this->createServerProcess($address, $docRoot, $projectRoot, $appConfig);
        $process->start(function ($type, $buffer) use ($log): void {
            $log->write($buffer);
        });
        $pid = $process->getPid();
        $this->writeServerInfo($address, $pid, [
            'appId' => $appId,
            'docRoot' => $docRoot,
            'logFile' => $logFile,
            'projectRoot' => $projectRoot,
        ]);

        $this->stdErr->writeln(sprintf(
            'Web server started at <info>http://%s</info> for app <info>%s</info>',
            $address,
            $appId,
        ));

        if ($logFile) {
            $this->stdErr->writeln('Logs are written to: ' . $logFile);
        }

        $this->stdErr->writeln('Quitting this command (with Ctrl+C or equivalent) will stop the server.');

        sleep(1);

        if ($process->isRunning()) {
            $urlService = $this->url;
            $urlService->openUrl('http://' . $address);
        }

        try {
            $process->wait();
        } catch (RuntimeException $e) {
            // 15 = SIGTERM
            if (strpos($e->getMessage(), '"15"')) {
                $this->stdErr->writeln('The server was stopped');
                return 1;
            }
            throw $e;
        }

        return $process->isSuccessful() ? 0 : 1;
    }
}
