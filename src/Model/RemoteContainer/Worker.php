<?php

declare(strict_types=1);

namespace Platformsh\Cli\Model\RemoteContainer;

use Platformsh\Cli\Model\AppConfig;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Deployment\Worker as DeployedWorker;

readonly class Worker implements RemoteContainerInterface
{
    public function __construct(private DeployedWorker $worker, private Environment $environment) {}

    public function getSshUrl($instance = ''): string
    {
        return $this->environment->getSshUrl($this->worker->name, $instance);
    }

    public function getName(): string
    {
        return $this->worker->name;
    }

    public function getConfig(): AppConfig
    {
        return new AppConfig($this->worker->getProperties());
    }

    public function getRuntimeOperations(): array
    {
        return $this->worker->getRuntimeOperations();
    }
}
