<?php

namespace Platformsh\Cli\Service;

use Doctrine\Common\Cache\CacheProvider;

/**
 * A service for reading environment variables from the remote environment.
 */
class RemoteEnvVars
{

    protected $shellHelper;
    protected $config;
    protected $ssh;
    protected $cache;

    /**
     * Constructor (dependencies are injected via the DIC).
     *
     * @param Ssh             $ssh
     * @param CacheProvider   $cache
     * @param Shell           $shellHelper
     * @param Config          $config
     */
    public function __construct(Ssh $ssh, CacheProvider $cache, Shell $shellHelper, Config $config)
    {
        $this->ssh = $ssh;
        $this->cache = $cache;
        $this->shellHelper = $shellHelper;
        $this->config = $config;
    }

    /**
     * Read an environment variable from a remote application.
     *
     * @param string $variable The unprefixed name of the variable.
     * @param string $sshUrl   The SSH URL to the application.
     * @param bool   $refresh  Whether to refresh the cache.
     * @param int    $ttl      The cache lifetime of the result.
     * @param bool   $prefix   Whether to prepend the service.env_prefix.
     *
     * @throws \Symfony\Component\Process\Exception\RuntimeException
     *   If the SSH command fails.
     *
     * @return string The environment variable or an empty string.
     */
    public function getEnvVar($variable, $sshUrl, $refresh = false, $ttl = 3600, $prefix = true)
    {
        $varName = $prefix ? $this->config->get('service.env_prefix') . $variable : $variable;
        $cacheKey = 'env-' . $sshUrl . '-' . $varName;
        $cached = $this->cache->fetch($cacheKey);
        if ($refresh || $cached === false) {
            $args = ['ssh'];
            $args = array_merge($args, $this->ssh->getSshArgs());
            $args[] = $sshUrl;
            $args[] = 'echo $' . $varName;
            $cached = $this->shellHelper->execute($args, null, true);
            $this->cache->save($cacheKey, $cached, $ttl);
        }

        return $cached ?: '';
    }

    /**
     * Read a complex environment variable (an associative array) from the application.
     *
     * @see \Platformsh\Cli\Service\RemoteEnvVars::getEnvVar()
     *
     * @param string $variable
     * @param string $sshUrl
     * @param bool   $refresh
     * @param int    $ttl
     * @param bool   $prefix
     *
     * @return array
     */
    public function getArrayEnvVar($variable, $sshUrl, $refresh = false, $ttl = 3600, $prefix = true)
    {
        $value = $this->getEnvVar($variable, $sshUrl, $refresh, $ttl, $prefix);

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
