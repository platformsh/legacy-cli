<?php

namespace Platformsh\Cli\Model\RemoteContainer;

use Platformsh\Cli\Model\AppConfig;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Deployment\Worker as DeployedWorker;

class Worker implements RemoteContainerInterface
{
    private $worker;
    private $environment;

    /**
     * @param \Platformsh\Client\Model\Deployment\Worker $worker
     * @param \Platformsh\Client\Model\Environment       $environment
     */
    public function __construct(DeployedWorker $worker, Environment $environment) {
        $this->worker = $worker;
        $this->environment = $environment;
    }

    /**
     * {@inheritdoc}
     */
    public function getSshUrl()
    {
        return $this->environment->getWorkerSshUrl($this->worker);
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
    public function getType()
    {
        return 'worker';
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig() {
        return new AppConfig($this->worker->getProperties());
    }
}
