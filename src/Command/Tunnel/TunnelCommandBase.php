<?php
namespace Platformsh\Cli\Command\Tunnel;

use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Config;
use Symfony\Contracts\Service\Attribute\Required;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\PortUtil;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Process\Process;

abstract class TunnelCommandBase extends CommandBase
{
    private Io $io;
    private Selector $selector;
    private Relationships $relationships;
    private Config $config;

    protected const LOCAL_IP = '127.0.0.1';

    protected ?array $tunnelInfo = null;
    protected bool $canBeRunMultipleTimes = false;

    #[Required]
    public function autowire(Config $config, Io $io, Relationships $relationships, Selector $selector) : void
    {
        $this->config = $config;
        $this->relationships = $relationships;
        $this->selector = $selector;
        $this->io = $io;
    }

    /**
     * Checks whether a tunnel is already open.
     */
    protected function isTunnelOpen(array $tunnel): false|array
    {
        foreach ($this->getTunnelInfo() as $info) {
            if ($this->tunnelsAreEqual($tunnel, $info)) {
                if (isset($info['pid']) && function_exists('posix_kill') && !posix_kill($info['pid'], 0)) {
                    $this->io->debug(sprintf(
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
     * Gets info on currently open tunnels.
     */
    protected function getTunnelInfo(bool $open = true): array
    {
        if (!isset($this->tunnelInfo)) {
            $this->tunnelInfo = [];
            // @todo move this to State service (in a new major version)
            $filename = $this->config->getWritableUserDir() . '/tunnel-info.json';
            if (file_exists($filename)) {
                $this->io->debug(sprintf('Loading tunnel info from %s', $filename));
                $this->tunnelInfo = (array) json_decode(file_get_contents($filename), true);
            }
        }

        if ($open) {
            $needsSave = false;
            foreach ($this->tunnelInfo as $key => $tunnel) {
                if (isset($tunnel['pid']) && function_exists('posix_kill') && !posix_kill($tunnel['pid'], 0)) {
                    $this->io->debug(sprintf(
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

    protected function saveTunnelInfo(): void
    {
        $filename = $this->config->getWritableUserDir() . '/tunnel-info.json';
        if (!empty($this->tunnelInfo)) {
            $this->io->debug('Saving tunnel info to: ' . $filename);
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
    protected function closeTunnel(array $tunnel): bool
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
        $this->tunnelInfo = array_filter($this->tunnelInfo, fn($info): bool => !$this->tunnelsAreEqual($info, $tunnel));
        $this->saveTunnelInfo();

        return $success;
    }

    /**
     * Automatically determines the best port for a new tunnel.
     */
    protected function getPort(int$default = 30000): int
    {
        $ports = [];
        foreach ($this->getTunnelInfo() as $tunnel) {
            $ports[] = $tunnel['localPort'];
        }

        return PortUtil::getPort($ports ? max($ports) + 1 : $default);
    }

    protected function openLog(string $logFile): OutputInterface|false
    {
        $logResource = fopen($logFile, 'a');
        if ($logResource) {
            return new StreamOutput($logResource, OutputInterface::VERBOSITY_VERBOSE);
        }

        return false;
    }

    private function getTunnelKey(array $tunnel): string
    {
        return implode('--', [
            $tunnel['projectId'],
            $tunnel['environmentId'],
            $tunnel['appName'],
            $tunnel['relationship'],
            $tunnel['serviceKey'],
        ]);
    }

    protected function getTunnelUrl(array $tunnel, array $service): string
    {
        $localService = array_merge($service, array_intersect_key([
            'host' => self::LOCAL_IP,
            'port' => $tunnel['localPort'],
        ], $service));

        return $this->relationships->buildUrl($localService);
    }

    private function tunnelsAreEqual(array $tunnel1, array $tunnel2): bool
    {
        return $this->getTunnelKey($tunnel1) === $this->getTunnelKey($tunnel2);
    }

    protected function getPidFile(array $tunnel): string
    {
        $key = $this->getTunnelKey($tunnel);
        $dir = $this->config->getWritableUserDir() . '/.tunnels';
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            throw new \RuntimeException('Failed to create directory: ' . $dir);
        }

        return $dir . '/' . preg_replace('/[^0-9a-z.]+/', '-', $key) . '.pid';
    }

    protected function createTunnelProcess(string $url, string $remoteHost, int $remotePort, int $localPort, array $extraArgs = []): Process
    {
        $args = ['ssh', '-n', '-N', '-L', implode(':', [$localPort, $remoteHost, $remotePort]), $url];
        $args = array_merge($args, $extraArgs);
        $process = new Process($args);
        $process->setTimeout(null);

        return $process;
    }

    /**
     * Filters a list of tunnels by the currently selected project/environment.
     */
    protected function filterTunnels(array $tunnels, InputInterface $input): array
    {
        if (!$input->getOption('project') && !$this->selector->getProjectRoot()) {
            return $tunnels;
        }
        $selection = $this->selector->getSelection($input, new SelectorConfig(envRequired: false));
        $project = $selection->getProject();
        $environment = $selection->hasEnvironment() ? $selection->getEnvironment() : null;
        $appName = $selection->hasEnvironment() ? $selection->getAppName() : null;
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
     * Formats a tunnel's relationship as a string.
     *
     * @param array $tunnel
     *
     * @return string
     */
    protected function formatTunnelRelationship(array $tunnel): string
    {
        return $tunnel['serviceKey'] > 0
            ? sprintf('%s.%d', $tunnel['relationship'], $tunnel['serviceKey'])
            : $tunnel['relationship'];
    }
}
