<?php
namespace Platformsh\Cli\Command\Server;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Local\LocalProject;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Process\ProcessBuilder;

abstract class ServerCommandBase extends CommandBase
{
    protected $serverInfo;
    protected $local = true;

    /**
     * Check whether another server is running for an app.
     *
     * @param string $appId
     * @param string $projectRoot
     *
     * @return bool|array
     */
    protected function isServerRunningForApp($appId, $projectRoot)
    {
        foreach ($this->getServerInfo() as $address => $server) {
            if ($server['appId'] === $appId && $server['projectRoot'] === $projectRoot) {
                if (function_exists('posix_kill') && !posix_kill($server['pid'], 0)) {
                    $this->stopServer($address);
                    continue;
                }
                return $server;
            }
        }

        return false;
    }

    /**
     * Check whether another server is running at an address.
     *
     * @param string $address
     *
     * @return bool|int
     */
    protected function isServerRunningForAddress($address)
    {
        $lockFile = $this->getLockFile($address);
        $serverInfo = $this->getServerInfo();
        if (file_exists($lockFile)) {
            $pid = file_get_contents($lockFile);
        }
        elseif (isset($serverInfo[$address])) {
            $pid = $serverInfo[$address]['pid'];
        }

        if (!empty($pid) && (!function_exists('posix_kill') || posix_kill($pid, 0))) {
            return $pid;
        }
        elseif (!empty($pid)) {
            // The PID is no longer valid. Delete the lock file and
            // continue.
            $this->stopServer($address);
        }

        list($hostname, $port) = explode(':', $address);
        if (false !== $fp = @fsockopen($hostname, $port, $errno, $errstr, 10)) {
            fclose($fp);
            return true;
        }

        return false;
    }

    /**
     * Get info on currently running servers.
     *
     * @param bool $running
     *
     * @return array
     */
    protected function getServerInfo($running = true)
    {
        if (!isset($this->serverInfo)) {
            $this->serverInfo = [];
            $filename = $this->getConfigDir() . '/local-servers.json';
            if (file_exists($filename)) {
                $this->serverInfo = (array) json_decode(file_get_contents($filename), TRUE);
            }
        }

        if ($running) {
            return array_filter($this->serverInfo, function ($server) {
                if (function_exists('posix_kill') && !posix_kill($server['pid'], 0)) {
                    $this->stopServer($server['address']);
                    return false;
                }

                return true;
            });
        }

        return $this->serverInfo;
    }

    protected function saveServerInfo()
    {
        $filename = $this->getConfigDir() . '/local-servers.json';
        if (!empty($this->serverInfo)) {
            if (!file_put_contents($filename, json_encode($this->serverInfo))) {
                throw new \RuntimeException('Failed to write server info to: ' . $filename);
            }
        }
        else {
            unlink($filename);
        }
    }

    /**
     * Stop a running server.
     *
     * @param string $address
     * @param int|null $pid
     *
     * @return bool
     *   True on success, false on failure.
     */
    protected function stopServer($address, $pid = null)
    {
        $success = true;
        if ($pid && function_exists('posix_kill')) {
            $success = posix_kill($pid, SIGTERM);
            if (!$success) {
                $this->stdErr->writeln(sprintf('Failed to kill process <error>%d</error> (POSIX error %s)', $pid, posix_get_last_error()));
            }
        }
        $lockFile = $this->getLockFile($address);
        if (file_exists($lockFile)) {
            $success = unlink($lockFile) && $success;
        }
        unset($this->serverInfo[$address]);
        $this->saveServerInfo();

        return $success;
    }

    /**
     * @param string $address
     * @param int $pid
     * @param array $info
     */
    protected function writeServerInfo($address, $pid, array $info = [])
    {
        file_put_contents($this->getLockFile($address), $pid);
        list($ip, $port) = explode(':', $address);
        $this->serverInfo[$address] = $info + [
            'address' => $address,
            'pid' => $pid,
            'port' => $port,
            'ip' => $ip,
        ];
        $this->saveServerInfo();
    }

    /**
     * Automatically determine the best port for a new server.
     *
     * @param int $default
     *
     * @return int
     */
    protected function getPort($default = 3000)
    {
        $ports = [];
        foreach ($this->getServerInfo() as $address => $server) {
            $ports[] = $server['port'];
        }

        return $ports ? max($ports) + 1 : $default;
    }

    /**
     * @param int $port
     *
     * @return bool
     */
    protected function validatePort($port)
    {
        if (!is_numeric($port) || $port < 0 || $port > 65535) {
            return false;
        }

        static $unsafePorts = [
          1,    // tcpmux
          7,    // echo
          9,    // discard
          11,   // systat
          13,   // daytime
          15,   // netstat
          17,   // qotd
          19,   // chargen
          20,   // ftp data
          21,   // ftp access
          22,   // ssh
          23,   // telnet
          25,   // smtp
          37,   // time
          42,   // name
          43,   // nicname
          53,   // domain
          77,   // priv-rjs
          79,   // finger
          87,   // ttylink
          95,   // supdup
          101,  // hostriame
          102,  // iso-tsap
          103,  // gppitnp
          104,  // acr-nema
          109,  // pop2
          110,  // pop3
          111,  // sunrpc
          113,  // auth
          115,  // sftp
          117,  // uucp-path
          119,  // nntp
          123,  // NTP
          135,  // loc-srv /epmap
          139,  // netbios
          143,  // imap2
          179,  // BGP
          389,  // ldap
          465,  // smtp+ssl
          512,  // print / exec
          513,  // login
          514,  // shell
          515,  // printer
          526,  // tempo
          530,  // courier
          531,  // chat
          532,  // netnews
          540,  // uucp
          556,  // remotefs
          563,  // nntp+ssl
          587,  // stmp?
          601,  // ??
          636,  // ldap+ssl
          993,  // ldap+ssl
          995,  // pop3+ssl
          2049, // nfs
          3659, // apple-sasl / PasswordServer
          4045, // lockd
          6000, // X11
          6665, // Alternate IRC [Apple addition]
          6666, // Alternate IRC [Apple addition]
          6667, // Standard IRC [Apple addition]
          6668, // Alternate IRC [Apple addition]
          6669, // Alternate IRC [Apple addition]
        ];

        return !in_array($port, $unsafePorts);
    }

    /**
     * Determine the name of the lock file for a particular web server process.
     *
     * @param string $address An address/port tuple
     *
     * @return string The filename
     */
    protected function getLockFile($address)
    {
        return $this->getConfigDir() . '/server-' . preg_replace('/\W+/', '-', $address) . '.pid';
    }

    /**
     * Creates a process to start PHP's built-in web server.
     *
     * @param string $address
     * @param string $documentRoot
     * @param string $router
     *
     * @return \Symfony\Component\Process\Process The process
     */
    protected function createServerProcess($address, $documentRoot, $router)
    {
        $this->showSecurityWarning();

        $arguments = [$this->getHelper('shell')->resolveCommand('php')];

        foreach ($this->getServerPhpConfig() as $item => $value) {
            $arguments[] = sprintf('-d %s="%s"', $item, $value);
        }

        $arguments = array_merge($arguments, [
          '-t',
          $documentRoot,
          '-S',
          $address,
          $router,
        ]);

        // An 'exec' is needed to stop creating two processes on some OSs.
        if (strpos(PHP_OS, 'WIN') === false) {
            array_unshift($arguments, 'exec');
        }

        $builder = new ProcessBuilder($arguments);
        $builder->setTimeout(null);

        return $builder->getProcess();
    }

    /**
     * Get custom PHP configuration for the built-in web server.
     *
     * @return array
     */
    protected function getServerPhpConfig()
    {
        $phpConfig = [];

        // Ensure $_ENV is populated.
        $variables_order = ini_get('variables_order');
        if (strpos($variables_order, 'E') === false) {
            $phpConfig['variables_order'] = 'E' . $variables_order;
        }

        return $phpConfig;
    }

    /**
     * Create a router file.
     *
     * @param array $appConfig
     * @param string $projectRoot
     *
     * @return string|bool
     *   The absolute path to the file on success, false on failure.
     */
    protected function createRouter(array $appConfig, $projectRoot)
    {
        static $created = [];

        $stack = 'php';
        if (isset($appConfig['type'])) {
            list($stack, $version) = explode(':', $appConfig['type'], 2);
            if ($stack === 'hhvm') {
                $stack = 'php';
            }
            if ($stack === 'php' && $version && version_compare(PHP_VERSION, $version, '<')) {
                $this->stdErr->writeln(
                  sprintf("<comment>Warning:</comment> your local PHP version is %s, but the app expects %s", PHP_VERSION, $version)
                );
            }
        }

        $router_src = sprintf('%s/resources/router/router-%s.php', CLI_ROOT, $stack);
        if (!file_exists($router_src)) {
            $this->stdErr->writeln(sprintf('Router not found for application type: <error>%s</error>', $appConfig['type']));
            $this->stdErr->writeln('This app type is not supported in the CLI yet');
            return false;
        }

        $router = $projectRoot . '/.' . basename($router_src);
        if (!isset($created[$router])) {
            if (!file_put_contents($router, file_get_contents($router_src))) {
                $this->stdErr->writeln(sprintf('Could not create router file: <error>%s</error>', $router));
                return false;
            }
            $created[$router] = true;
        }

        return $router;
    }

    /**
     * @param string $logFile
     *
     * @return OutputInterface|false
     */
    protected function openLog($logFile)
    {
        $logResource = fopen($logFile, 'a');
        if ($logResource) {
            return new StreamOutput($logResource);
        }

        return false;
    }

    /**
     * Create the virtual environment variables for a local server.
     *
     * @param string $projectRoot
     * @param string $docRoot
     * @param array $appConfig
     *
     * @return array
     */
    protected function createEnv($projectRoot, $docRoot, array $appConfig)
    {
        $env = [
          'PLATFORM_ENVIRONMENT' => '_local',
          'PLATFORM_APPLICATION' => base64_encode(json_encode($appConfig)),
          'PLATFORM_APPLICATION_NAME' => isset($appConfig['name']) ? $appConfig['name'] : '',
          'PLATFORM_DOCUMENT_ROOT' => realpath($docRoot),
        ];

        if (dirname(dirname($docRoot)) === $projectRoot . '/' . LocalProject::BUILD_DIR) {
            $env['PLATFORM_APP_DIR'] = dirname($docRoot);
        }

        if ($projectRoot === $this->getProjectRoot()) {
            try {
                $project = $this->getCurrentProject();
                if ($project) {
                    $env['PLATFORM_PROJECT'] = $project->id;
                }
            }
            catch (\Exception $e) {
                // Ignore errors
            }
        }

        return $env;
    }

    protected function showSecurityWarning()
    {
        static $shown;
        if ($shown) {
            return;
        }
        $this->stdErr->writeln('<comment>Warning:</comment> this uses the PHP built-in web server, which is neither secure nor reliable for production use');
        $shown = true;
    }
}
