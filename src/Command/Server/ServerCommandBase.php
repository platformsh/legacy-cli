<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Server;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Service\Config;
use Symfony\Contracts\Service\Attribute\Required;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\PortUtil;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

abstract class ServerCommandBase extends CommandBase
{
    private Selector $selector;
    private Shell $shell;
    private LocalProject $localProject;
    private Config $config;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $serverInfo = null;

    #[Required]
    public function autowire(Config $config, LocalProject $localProject, Selector $selector, Shell $shell): void
    {
        $this->config = $config;
        $this->localProject = $localProject;
        $this->shell = $shell;
        $this->selector = $selector;
    }

    public function isEnabled(): bool
    {
        return $this->config->isExperimentEnabled('enable_local_server')
            && parent::isEnabled();
    }

    /**
     * Checks whether another server is running for an app.
     *
     * @return array<string, mixed>|false
     */
    protected function isServerRunningForApp(string $appId, string $projectRoot): array|false
    {
        foreach ($this->getServerInfo() as $address => $server) {
            if ($server['appId'] === $appId && $server['projectRoot'] === $projectRoot) {
                if ($this->isProcessDead($server['pid'])) {
                    $this->stopServer($address);
                    continue;
                }
                return $server;
            }
        }

        return false;
    }

    /**
     * Check whether a process is no longer running.
     *
     * @param int $pid
     *
     * @return bool
     */
    private function isProcessDead(int $pid): bool
    {
        return function_exists('posix_kill') && !posix_kill($pid, 0);
    }

    /**
     * Checks whether another server is running at an address.
     */
    protected function isServerRunningForAddress(string $address): bool|int
    {
        $pidFile = $this->getPidFile($address);
        $serverInfo = $this->getServerInfo();
        if (file_exists($pidFile)) {
            $pid = (int) file_get_contents($pidFile);
        } elseif (isset($serverInfo[$address])) {
            $pid = $serverInfo[$address]['pid'];
        }

        if (!empty($pid) && !$this->isProcessDead($pid)) {
            return $pid;
        } elseif (!empty($pid)) {
            // The PID is no longer valid. Delete the lock file and
            // continue.
            $this->stopServer($address);
        }

        [$hostname, $port] = explode(':', $address);

        return PortUtil::isPortInUse((int) $port, $hostname);
    }

    /**
     * Gets info on currently running servers.
     *
     * @return array<string, array{
     *     pid: int,
     *     appId: string,
     *     projectRoot: string,
     *     logFile: string,
     *     docRoot: string,
     *     address: string,
     *     port: int,
     *     ip: string,
     * }>
     */
    protected function getServerInfo(bool $running = true): array
    {
        if (!isset($this->serverInfo)) {
            $this->serverInfo = [];
            // @todo move this to State service (in a new major version)
            $filename = $this->config->getWritableUserDir() . '/local-servers.json';
            if (file_exists($filename)) {
                $this->serverInfo = (array) json_decode((string) file_get_contents($filename), true);
            }
        }

        if ($running) {
            return array_filter($this->serverInfo, function (array $server): bool {
                if ($this->isProcessDead($server['pid'])) {
                    $this->stopServer($server['address']);
                    return false;
                }

                return true;
            });
        }

        return $this->serverInfo;
    }

    private function saveServerInfo(): void
    {
        $filename = $this->config->getWritableUserDir() . '/local-servers.json';
        if (!empty($this->serverInfo)) {
            if (!file_put_contents($filename, json_encode($this->serverInfo))) {
                throw new \RuntimeException('Failed to write server info to: ' . $filename);
            }
        } else {
            unlink($filename);
        }
    }

    /**
     * Stops a running server.
     */
    protected function stopServer(string $address, ?int $pid = null): bool
    {
        $success = true;
        if ($pid && function_exists('posix_kill')) {
            $success = posix_kill($pid, SIGTERM);
            if (!$success) {
                $this->stdErr->writeln(sprintf(
                    'Failed to kill process <error>%d</error> (POSIX error %s)',
                    $pid,
                    posix_get_last_error(),
                ));
            }
        }
        $pidFile = $this->getPidFile($address);
        if (file_exists($pidFile)) {
            $success = unlink($pidFile) && $success;
        }
        unset($this->serverInfo[$address]);
        $this->saveServerInfo();

        return $success;
    }

    /**
     * @param array{appId: string, projectRoot: string, logFile: string, docRoot: string} $info
     */
    protected function writeServerInfo(string $address, int $pid, array $info): void
    {
        file_put_contents($this->getPidFile($address), $pid);
        [$ip, $port] = explode(':', $address);
        $this->serverInfo[$address] = $info + [
            'address' => $address,
            'pid' => $pid,
            'port' => (int) $port,
            'ip' => $ip,
        ];
        $this->saveServerInfo();
    }

    /**
     * Automatically determines the best port for a new server.
     */
    protected function getPort(int $default = 3000): int
    {
        $ports = [];
        foreach ($this->getServerInfo() as $server) {
            $ports[] = $server['port'];
        }

        return PortUtil::getPort($ports ? max($ports) + 1 : $default);
    }

    /**
     * Determine the name of the lock file for a particular web server process.
     *
     * @param string $address An address/port tuple
     *
     * @return string The filename
     */
    protected function getPidFile(string $address): string
    {
        return $this->config->getWritableUserDir() . '/server-' . preg_replace('/\W+/', '-', $address) . '.pid';
    }

    /**
     * Creates a process to start a web server.
     *
     * @param string $address
     * @param string $docRoot
     * @param string $projectRoot
     * @param array<string, mixed> $appConfig
     * @param array<string, string> $env
     *
     * @return Process
     *@throws \Exception
     *
     */
    protected function createServerProcess(string $address, string $docRoot, string $projectRoot, array $appConfig, array $env = []): Process
    {
        if (isset($appConfig['type'])) {
            $type = explode(':', (string) $appConfig['type'], 2);
            $version = $type[1] ?? false;
            $shell = $this->shell;
            $localPhpVersion = $shell->getPhpVersion();
            if ($type[0] === 'php' && $version && version_compare($localPhpVersion, $version, '<')) {
                $this->stdErr->writeln(sprintf(
                    '<comment>Warning:</comment> your local PHP version is %s, but the app expects %s',
                    $localPhpVersion,
                    $version,
                ));
            }
        }

        $arguments = [];

        if (isset($appConfig['web']['commands']['start'])) {
            // Bail out. We can't support custom 'start' commands for now.
            throw new \Exception(
                "Not supported: the CLI doesn't support starting a server with a custom 'start' command",
            );
        }

        $router = $this->createRouter($projectRoot);

        $this->showSecurityWarning();

        $arguments[] = (new PhpExecutableFinder())->find() ?: PHP_BINARY;

        foreach ($this->getServerPhpConfig() as $item => $value) {
            $arguments[] = sprintf('-d %s="%s"', $item, $value);
        }

        $arguments = array_merge($arguments, [
            '-t',
            $docRoot,
            '-S',
            $address,
            $router,
        ]);

        $process = new Process($arguments);
        $process->setTimeout(null);
        $env += $this->createEnv($projectRoot, $docRoot, $address, $appConfig);
        $process->setEnv($env);
        $envPrefix = $this->config->getStr('service.env_prefix');
        if (isset($env[$envPrefix . 'APP_DIR'])) {
            $process->setWorkingDirectory($env[$envPrefix . 'APP_DIR']);
        }

        return $process;
    }

    /**
     * Get custom PHP configuration for the built-in web server.
     *
     * @return array<string, string>
     */
    private function getServerPhpConfig(): array
    {
        $phpConfig = [];

        // Ensure $_ENV is populated.
        $variables_order = ini_get('variables_order');
        if (!str_contains((string) $variables_order, 'E')) {
            $phpConfig['variables_order'] = 'E' . $variables_order;
        }

        return $phpConfig;
    }

    /**
     * Create a router file.
     *
     * @param string $projectRoot
     *
     * @return string
     *   The absolute path to the router file.
     */
    private function createRouter(string $projectRoot): string
    {
        static $created = [];

        $router_src = CLI_ROOT . '/resources/router/router.php';
        if (!file_exists($router_src)) {
            throw new \RuntimeException(sprintf('Router not found: <error>%s</error>', $router_src));
        }

        $router = $projectRoot . '/' . $this->config->getStr('local.local_dir') . '/' . basename($router_src);
        if (!isset($created[$router])) {
            if (!file_put_contents($router, file_get_contents($router_src))) {
                throw new \RuntimeException(sprintf('Could not create router file: <error>%s</error>', $router));
            }
            $created[$router] = true;
        }

        return $router;
    }

    protected function openLog(string $logFile): false|OutputInterface
    {
        $logResource = fopen($logFile, 'a');
        if ($logResource) {
            return new StreamOutput($logResource, OutputInterface::VERBOSITY_VERBOSE);
        }

        return false;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getRoutesList(string $projectRoot, string $address): array
    {
        $routesConfig = (array) $this->localProject->readProjectConfigFile($projectRoot, 'routes.yaml');

        $routes = [];
        foreach ($routesConfig as $route => $config) {
            // If the route starts with http://{default}, replace it with the
            // $address. This can't accommodate subdomains or HTTPS routes, so
            // those are ignored.
            $url = str_starts_with($route, 'http://{default}')
                ? 'http://' . $address . substr($route, 16)
                : $route;
            if (str_contains($url, '{default}')) {
                continue;
            }
            $routes[$url] = $config + ['original_url' => $route];
        }

        return $routes;
    }

    /**
     * Creates the virtual environment variables for a local server.
     *
     * @param array<string, mixed> $appConfig
     *
     * @return array<string, string>
     */
    protected function createEnv(string $projectRoot, string $docRoot, string $address, array $appConfig): array
    {
        $realDocRoot = realpath($docRoot);
        if (!$realDocRoot) {
            throw new \RuntimeException('Failed to resolve directory: ' . $docRoot);
        }
        $envPrefix = $this->config->getStr('service.env_prefix');
        $env = [
            '_PLATFORM_VARIABLES_PREFIX' => $envPrefix,
            $envPrefix . 'ENVIRONMENT' => '_local',
            $envPrefix . 'APPLICATION' => base64_encode((string) json_encode($appConfig)),
            $envPrefix . 'APPLICATION_NAME' => $appConfig['name'] ?? '',
            $envPrefix . 'DOCUMENT_ROOT' => $realDocRoot,
            $envPrefix . 'ROUTES' => base64_encode((string) json_encode($this->getRoutesList($projectRoot, $address))),
        ];

        [$env['IP'], $env['PORT']] = explode(':', $address);

        if (dirname($realDocRoot, 2) === $projectRoot . '/' . $this->config->getStr('local.build_dir')) {
            $env[$envPrefix . 'APP_DIR'] = dirname($realDocRoot);
        }

        if ($projectRoot === $this->selector->getProjectRoot()) {
            try {
                $project = $this->selector->getCurrentProject();
                if ($project) {
                    $env[$envPrefix . 'PROJECT'] = $project->id;
                }
            } catch (\Exception) {
                // Ignore errors
            }
        }

        return $env;
    }

    private function showSecurityWarning(): void
    {
        static $shown;
        if ($shown) {
            return;
        }
        $this->stdErr->writeln(
            '<comment>Warning:</comment> this uses the PHP built-in web server, which is neither secure nor reliable for production use',
        );
        $shown = true;
    }
}
