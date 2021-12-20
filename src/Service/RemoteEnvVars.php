<?php

namespace Platformsh\Cli\Service;

use Doctrine\Common\Cache\CacheProvider;
use Platformsh\Cli\Model\Host\HostInterface;
use Platformsh\Cli\Model\Host\LocalHost;

/**
 * A service for reading environment variables on a host.
 */
class RemoteEnvVars
{

    protected $config;
    protected $ssh;
    protected $cache;

    /**
     * Constructor (dependencies are injected via the DIC).
     *
     * @param Ssh             $ssh
     * @param CacheProvider   $cache
     * @param Config          $config
     */
    public function __construct(Ssh $ssh, CacheProvider $cache, Config $config)
    {
        $this->ssh = $ssh;
        $this->cache = $cache;
        $this->config = $config;
    }

    /**
     * Read an environment variable from a remote application.
     *
     * @param string $variable The unprefixed name of the variable.
     * @param HostInterface $host The host of the application.
     * @param bool $refresh Whether to refresh the cache.
     * @param int $ttl The cache lifetime of the result.
     *
     * @return string The environment variable or an empty string.
     */
    public function getEnvVar($variable, HostInterface $host, $refresh = false, $ttl = 3600)
    {
        $varName = $this->config->get('service.env_prefix') . $variable;
        if ($host instanceof LocalHost) {
            return getenv($varName) !== false ? getenv($varName) : '';
        }
        // We ssh to the environment and 'echo' the variable, and read stdout.
        // Sometimes a program in the environment will print errors or other
        // messages to stdout. To avoid the value being polluted by these
        // messages, we also 'echo' beginning and ending delimiters, and
        // extract the result from between them.
        $begin = '_BEGIN_ENV_VAR_';
        $end = '_END_ENV_VAR_';
        $cacheKey = 'env-' . $host->getCacheKey() . '--' . $varName;
        $value = $this->cache->fetch($cacheKey);
        if ($refresh || $value === false) {
            $output = $host->runCommand(\sprintf('echo -n \'%s\'"$%s"\'%s\'', $begin, $varName, $end));
            $value = $this->extractResult($output, $begin, $end);
            $this->cache->save($cacheKey, $value, $ttl);
        }

        return $value ?: '';
    }

    /**
     * Extracts a result from 'echo' output between beginning and ending delimiters.
     *
     * @param string $output
     * @param string $begin
     * @param string $end
     *
     * @return string
     */
    private function extractResult($output, $begin, $end)
    {
        $first = \strpos($output, $begin);
        $last = \strrpos($output, $end, $first);
        if ($first === false || $last === false) {
            return $output;
        }
        $offset = $first + \strlen($begin);
        $length = $last - $first - \strlen($begin);
        return \substr($output, $offset, $length);
    }

    /**
     * Read a complex environment variable (an associative array) from the application.
     *
     * @param string $variable
     * @param HostInterface $host
     * @param bool $refresh
     *
     * @return array
     * @see \Platformsh\Cli\Service\RemoteEnvVars::getEnvVar()
     */
    public function getArrayEnvVar($variable, HostInterface $host, $refresh = false)
    {
        $value = $this->getEnvVar($variable, $host, $refresh);

        return json_decode(base64_decode($value), true) ?: [];
    }

    /**
     * Clear caches for remote environment variables.
     *
     * @param string $sshUrl    The SSH URL to the application.
     * @param array  $variables A list of unprefixed variables.
     */
    public function clearCaches($sshUrl, array $variables = ['APPLICATION', 'RELATIONSHIPS', 'ROUTES'])
    {
        $prefix = $this->config->get('service.env_prefix');
        foreach ($variables as $variable) {
            $this->cache->delete('env-' . $sshUrl . '-' . $prefix . $variable);
        }
    }
}
