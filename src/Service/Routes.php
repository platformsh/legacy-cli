<?php

namespace Platformsh\Cli\Service;

use Doctrine\Common\Cache\CacheProvider;

class Routes
{

    protected $shellHelper;
    protected $config;
    protected $ssh;
    protected $cache;

    /**
     * @param Ssh             $ssh
     * @param CacheProvider   $cache
     * @param Shell           $shellHelper
     * @param Config          $config
     */
    public function __construct(
        Ssh $ssh,
        CacheProvider $cache,
        Shell $shellHelper,
        Config $config
    ) {
        $this->ssh = $ssh;
        $this->cache = $cache;
        $this->shellHelper = $shellHelper;
        $this->config = $config;
    }

    /**
     * @param string $sshUrl
     * @param bool   $refresh
     *
     * @return array
     */
    public function getRoutes($sshUrl, $refresh = false)
    {
        $cacheKey = 'routes-' . $sshUrl;
        $routes = $this->cache->fetch($cacheKey);
        if ($refresh || $routes === false) {
            $args = ['ssh'];
            $args = array_merge($args, $this->ssh->getSshArgs());
            $args[] = $sshUrl;
            $args[] = 'echo $' . $this->config->get('service.env_prefix') . 'ROUTES';
            $result = $this->shellHelper->execute($args, null, true);
            $routes = json_decode(base64_decode($result), true);
            $this->cache->save($cacheKey, $routes, 3600);
        }

        return $routes;
    }
}
