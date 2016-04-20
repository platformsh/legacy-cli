<?php
namespace Platformsh\Cli\Command\Server;

use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Local\Toolstack\Drupal;
use Platformsh\Cli\Util\PortUtil;
use Platformsh\Cli\Util\ProcessManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ServerStartCommand extends ServerCommandBase
{
    protected function configure()
    {
        $this
          ->setName('server:start')
          ->setDescription('Run PHP web server(s) for the local project')
          ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force starting servers')
          ->addOption('ip', null, InputOption::VALUE_REQUIRED, 'The IP address', '127.0.0.1')
          ->addOption('port', null, InputOption::VALUE_REQUIRED, 'The port of the first server')
          ->addOption('log', null, InputOption::VALUE_REQUIRED, 'The name of a log file. Defaults to local-server.log in the project root');
    }

    public function isEnabled()
    {
        return ProcessManager::supported() && parent::isEnabled();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectRoot = $this->getProjectRoot();
        $repositoryDir = $projectRoot . '/' . LocalProject::REPOSITORY_DIR;
        if (!$projectRoot || !is_dir($repositoryDir)) {
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

        $apps = LocalApplication::getApplications($repositoryDir);
        if (!count($apps)) {
            $this->stdErr->writeln(sprintf('No applications found in directory: %s', $repositoryDir));
            return 1;
        }

        $multiApp = count($apps) > 1;
        $webRoot = $projectRoot . '/' . LocalProject::WEB_ROOT;
        $items = [];
        foreach ($apps as $app) {
            $appId = $app->getId();
            $docRoot = $multiApp ? $webRoot . '/' . $app->getWebPath() : $webRoot;
            if (!file_exists($docRoot)) {
                $this->stdErr->writeln(sprintf('Document root not found for app <error>%s</error>: %s', $appId, $docRoot));
                $this->stdErr->writeln(sprintf('Build the application with: <comment>platform build</comment>'));
                continue;
            }
            $items[$appId] = [
              'docRoot' => $docRoot,
              'address' => sprintf('%s:%s', $ip, $port++),
              'name' => $app->getName(),
              'config' => $app->getConfig(),
            ];
            if (Drupal::isDrupal($app->getRoot())) {
                $items[$app->getId()]['config']['is_drupal'] = true;
            }
        }

        if (!$items) {
            return 1;
        }

        $logFile = $input->getOption('log') ?: $projectRoot . '/local-server.log';
        $log = $this->openLog($logFile);
        if (!$log) {
            $this->stdErr->writeln(sprintf('Failed to open log file for writing: <error>%s</error>', $logFile));
            return 1;
        }

        $processManager = new ProcessManager();

        // Fork the PHP process so that we can start servers asynchronously.
        $processManager->fork();

        $error = false;
        $processes = [];
        $force = $input->getOption('force');
        foreach ($items as $appId => $item) {
            $appName = $item['name'];
            $appConfig = $item['config'];
            $address = $item['address'];
            $docRoot = $item['docRoot'];

            if ($otherServer = $this->isServerRunningForApp($appId, $projectRoot)) {
                if (!$force) {
                    $this->stdErr->writeln(sprintf(
                      'A server is already running for the app <info>%s</info> at http://%s, PID %s',
                      $appId,
                      $otherServer['address'],
                      $otherServer['pid']
                    ));
                    continue;
                }

                // If the port was not manually specified, kill the old server and
                // take its address.
                $this->stdErr->writeln(sprintf(
                  'Stopping server for the app <info>%s</info> at http://%s',
                  $appId,
                  $otherServer['address']));
                $this->stopServer($address, $otherServer['pid']);
                sleep(1);

                // If the address was not manually specified, take the old server's
                // address.
                if (!$input->getOption('port') && $input->getOption('ip') === '127.0.0.1') {
                    $address = $otherServer['address'];
                }
            }
            elseif ($otherPid = $this->isServerRunningForAddress($address)) {
                if (!$force || $otherPid === true) {
                    $this->stdErr->writeln(sprintf(
                      'A server is already running at address: http://%s, PID %s',
                      $address,
                      $otherPid === true ? 'unknown' : $otherPid
                    ));
                    $error = true;
                    continue;
                }
                $this->stdErr->writeln(sprintf('Stopping existing server at <comment>http://%s</comment>', $address));
                $this->stopServer($address, $otherPid);
                sleep(1);
            }

            $pidFile = $this->getPidFile($address);
            $process = $this->createServerProcess($address, $docRoot, $projectRoot, $appConfig);
            $processes[$address] = $process;

            try {
                $processManager->startProcess($process, $pidFile, $log);
            }
            catch (\Exception $e) {
                $this->stdErr->writeln(sprintf('Failed to start server: %s', $e->getMessage()));
                unset($processes[$address]);
                $error = true;
                continue;
            }

            // Save metadata on the server.
            $pid = $process->getPid();
            $this->writeServerInfo($address, $pid, [
              'appId' => $appId,
              'docRoot' => $docRoot,
              'logFile' => $logFile,
              'projectRoot' => $projectRoot,
            ]);

            // Wait a small time to capture any immediate errors.
            usleep(100000);
            if (!$process->isRunning() && !$process->isSuccessful()) {
                $this->stdErr->writeln(trim($process->getErrorOutput()));
                unlink($this->getPidFile($address));
                unset($processes[$address]);
                $error = true;
                continue;
            }

            $this->stdErr->writeln(sprintf('Web server started at <info>http://%s</info> for app <info>%s</info>', $address, $appId));
        }

        if (count($processes)) {
            $this->stdErr->writeln(sprintf('Logs are written to: %s', $logFile));
            $this->stdErr->writeln('Use <comment>platform server:stop</comment> to stop server(s)');
        }

        // The terminal has received all necessary output, so we can stop the
        // parent process.
        $processManager->killParent($error);

        $processManager->monitor($log);

        return $error ? 1 : 0;
    }
}
