<?php

namespace Platformsh\Cli\Model\RemoteContainer;

use Platformsh\Cli\Model\AppConfig;
use Platformsh\Client\Model\Environment;

/**
 * Represents a broken environment, which may still allow SSH.
 *
 * Used when an environment doesn't have a working deployments API, due to
 * application validation errors on the server side.
 */
class BrokenEnv implements RemoteContainerInterface
{
    private $environment;
    private $appName;

    /**
     * @param \Platformsh\Client\Model\Environment $environment
     * @param string                               $appName
     */
    public function __construct(Environment $environment, $appName) {
        $this->environment = $environment;
        $this->appName = $appName;
    }

    /**
     * {@inheritdoc}
     */
    public function getSshUrl()
    {
        return $this->environment->getSshUrl($this->appName);
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return $this->appName;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig() {
        return new AppConfig(!empty($this->appName) ? ['name' => $this->appName] : []);
    }
}
