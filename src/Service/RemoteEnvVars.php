<?php

namespace Platformsh\Cli\Service;

use Doctrine\Common\Cache\CacheProvider;
use Platformsh\Cli\Model\Host\HostInterface;
use Platformsh\Cli\Model\Host\LocalHost;
use Platformsh\Cli\Util\StringUtil;

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
     * The cache is limited by the TTL, but it is also invalidated if the
     * $host->lastChanged() timestamp changes.
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
        $cacheKey = 'env-var-' . $host->getCacheKey() . '--' . $varName;
        /** @var false|array{'last_changed': string, 'value': string} $data */
        $data = $this->cache->fetch($cacheKey);
        if ($refresh || $data === false || $data['last_changed'] !== $host->lastChanged()) {
            $output = $host->runCommand(\sprintf('echo -n \'%s\'"$%s"\'%s\'', $begin, $varName, $end));
            $value = StringUtil::between((string) $output, $begin, $end);
            $data = ['last_changed' => $host->lastChanged(), 'value' => $value];
            $this->cache->save($cacheKey, $data, $ttl);
        } else {
            $value = $data['value'];
        }

        return $value ?: '';
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
}
