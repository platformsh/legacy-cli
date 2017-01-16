<?php
namespace Platformsh\Cli\Command\Tunnel;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\PortUtil;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Process\ProcessBuilder;

abstract class TunnelCommandBase extends CommandBase
{
    const LOCAL_IP = '127.0.0.1';

    protected $tunnelInfo;
    protected $canBeRunMultipleTimes = false;

    public function checkSupport()
    {
        $messages = [];
        foreach (['pcntl', 'posix'] as $extension) {
            if (!extension_loaded($extension)) {
                $messages[] = sprintf('The "%s" extension is required.', $extension);
            }
        }
        if (count($messages)) {
            throw new \RuntimeException(implode("\n", $messages));
        }
    }

    /**
     * Check whether a tunnel is already open.
     *
     * @param array $tunnel
     *
     * @return bool|array
     */
    protected function isTunnelOpen(array $tunnel)
    {
        foreach ($this->getTunnelInfo() as $info) {
            if ($this->tunnelsAreEqual($tunnel, $info)) {
                if (isset($info['pid']) && function_exists('posix_kill') && !posix_kill($info['pid'], 0)) {
                    $this->debug(sprintf(
                        'The tunnel at port %d is no longer open, removing from list',
                        $info['localPort']
                    ));
                    $this->closeTunnel($info);
                    continue;
                }

                return $info;
            }
        }

        return false;
    }

    /**
     * Get info on currently open tunnels.
     *
     * @param bool $open
     *
     * @return array
     */
    protected function getTunnelInfo($open = true)
    {
        if (!isset($this->tunnelInfo)) {
            $this->tunnelInfo = [];
            $filename = $this->config()->getUserConfigDir() . '/tunnel-info.json';
            if (file_exists($filename)) {
                $this->debug(sprintf('Loading tunnel info from %s', $filename));
                $this->tunnelInfo = (array) json_decode(file_get_contents($filename), true);
            }
        }

        if ($open) {
            $needsSave = false;
            foreach ($this->tunnelInfo as $key => $tunnel) {
                if (isset($tunnel['pid']) && function_exists('posix_kill') && !posix_kill($tunnel['pid'], 0)) {
                    $this->debug(sprintf(
                        'The tunnel at port %d is no longer open, removing from list',
                        $tunnel['localPort']
                    ));
                    unset($this->tunnelInfo[$key]);
                    $needsSave = true;
                }
            }
            if ($needsSave) {
                $this->saveTunnelInfo();
            }
        }

        return $this->tunnelInfo;
    }

    protected function saveTunnelInfo()
    {
        $filename = $this->config()->getUserConfigDir() . '/tunnel-info.json';
        if (!empty($this->tunnelInfo)) {
            $this->debug('Saving tunnel info to: ' . $filename);
            if (!file_put_contents($filename, json_encode($this->tunnelInfo))) {
                throw new \RuntimeException('Failed to write tunnel info to: ' . $filename);
            }
        } else {
            unlink($filename);
        }
    }

    /**
     * Close an open tunnel.
     *
     * @param array $tunnel
     *
     * @return bool
     *   True on success, false on failure.
     */
    protected function closeTunnel(array $tunnel)
    {
        $success = true;
        if (isset($tunnel['pid']) && function_exists('posix_kill')) {
            $success = posix_kill($tunnel['pid'], SIGTERM);
            if (!$success) {
                $this->stdErr->writeln(sprintf(
                    'Failed to kill process <error>%d</error> (POSIX error %s)',
                    $tunnel['pid'],
                    posix_get_last_error()
                ));
            }
        }
        $pidFile = $this->getPidFile($tunnel);
        if (file_exists($pidFile)) {
            $success = unlink($pidFile) && $success;
        }
        $this->tunnelInfo = array_filter($this->tunnelInfo, function ($info) use ($tunnel) {
            return !$this->tunnelsAreEqual($info, $tunnel);
        });
        $this->saveTunnelInfo();

        return $success;
    }

    /**
     * Automatically determine the best port for a new tunnel.
     *
     * @param int $default
     *
     * @return int
     */
    protected function getPort($default = 30000)
    {
        $ports = [];
        foreach ($this->getTunnelInfo() as $tunnel) {
            $ports[] = $tunnel['localPort'];
        }

        return PortUtil::getPort($ports ? max($ports) + 1 : $default);
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
     * @param array $tunnel
     *
     * @return string
     */
    protected function getTunnelKey(array $tunnel)
    {
        return implode('--', [
            $tunnel['projectId'],
            $tunnel['environmentId'],
            $tunnel['appName'],
            $tunnel['relationship'],
            $tunnel['serviceKey'],
        ]);
    }

    /**
     * @param array $tunnel1
     * @param array $tunnel2
     *
     * @return bool
     */
    protected function tunnelsAreEqual(array $tunnel1, array $tunnel2)
    {
        return $this->getTunnelKey($tunnel1) === $this->getTunnelKey($tunnel2);
    }

    /**
     * @param array $tunnel
     *
     * @return string
     */
    protected function getPidFile(array $tunnel)
    {
        $key = $this->getTunnelKey($tunnel);
        $dir = $this->config()->getUserConfigDir() . '/.tunnels';
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            throw new \RuntimeException('Failed to create directory: ' . $dir);
        }

        return $dir . '/' . preg_replace('/[^0-9a-z\.]+/', '-', $key) . '.pid';
    }

    /**
     * @param string $url
     * @param string $remoteHost
     * @param int $remotePort
     * @param int $localPort
     * @param array $extraArgs
     *
     * @return \Symfony\Component\Process\Process
     */
    protected function createTunnelProcess($url, $remoteHost, $remotePort, $localPort, array $extraArgs = [])
    {
        $args = ['ssh', '-n', '-N', '-L', implode(':', [$localPort, $remoteHost, $remotePort]), $url];
        if (strpos(PHP_OS, 'WIN') === false) {
            array_unshift($args, 'exec');
        }
        $args = array_merge($args, $extraArgs);

        return ProcessBuilder::create($args)->getProcess();
    }

    /**
     * Filter a list of tunnels by the currently selected project/environment.
     *
     * @param array          $tunnels
     * @param InputInterface $input
     *
     * @return array
     */
    protected function filterTunnels(array $tunnels, InputInterface $input)
    {
        if (!$input->getOption('project') && !$this->getProjectRoot()) {
            return $tunnels;
        }

        if (!$this->hasSelectedProject()) {
            $this->validateInput($input, true);
        }
        $project = $this->getSelectedProject();
        $environment = $this->hasSelectedEnvironment() ? $this->getSelectedEnvironment() : null;
        $appName = $this->selectApp($input);
        foreach ($tunnels as $key => $tunnel) {
            if ($tunnel['projectId'] !== $project->id
                || ($environment !== null && $tunnel['environmentId'] !== $environment->id)
                || ($appName !== null && $tunnel['appName'] !== $appName)) {
                unset($tunnels[$key]);
            }
        }

        return $tunnels;
    }

    /**
     * Format a tunnel's relationship as a string.
     *
     * @param array $tunnel
     *
     * @return string
     */
    protected function formatTunnelRelationship(array $tunnel)
    {
        return $tunnel['serviceKey'] > 0
            ? sprintf('%s.%d', $tunnel['relationship'], $tunnel['serviceKey'])
            : $tunnel['relationship'];
    }
}
