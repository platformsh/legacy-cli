<?php

namespace Platformsh\Cli\Model\RemoteContainer;

use Platformsh\Cli\Model\AppConfig;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Deployment\Worker as DeployedWorker;

class Worker implements RemoteContainerInterface
{
    /**
     * @param \Platformsh\Client\Model\Deployment\Worker $worker
     * @param Environment $environment
     */
    public function __construct(private readonly DeployedWorker $worker, private readonly Environment $environment)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function getSshUrl($instance = ''): string
    {
        return $this->environment->getSshUrl($this->worker->name, $instance);
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return $this->worker->name;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig(): AppConfig {
        return new AppConfig($this->worker->getProperties());
    }

    /**
     * {@inheritDoc}
     */
    public function getRuntimeOperations()
    {
        return $this->worker->getRuntimeOperations();
    }
}
