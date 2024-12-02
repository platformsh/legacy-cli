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
    /**
     * @param Environment $environment
     * @param string                               $appName
     */
    public function __construct(private readonly Environment $environment, private $appName)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function getSshUrl($instance = ''): string
    {
        return $this->environment->getSshUrl($this->appName, $instance);
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
    public function getConfig(): AppConfig {
        return new AppConfig(!empty($this->appName) ? ['name' => $this->appName] : []);
    }

    /**
     * {@inheritDoc}
     */
    public function getRuntimeOperations(): array
    {
        return [];
    }
}
