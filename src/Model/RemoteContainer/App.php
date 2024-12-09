<?php

namespace Platformsh\Cli\Model\RemoteContainer;

use Platformsh\Cli\Model\AppConfig;
use Platformsh\Client\Model\Deployment\WebApp;
use Platformsh\Client\Model\Environment;

class App implements RemoteContainerInterface
{
    /**
     * @param WebApp $webApp
     * @param Environment $environment
     */
    public function __construct(private readonly WebApp $webApp, private readonly Environment $environment)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function getSshUrl($instance = ''): string
    {
        return $this->environment->getSshUrl($this->webApp->name, $instance);
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return $this->webApp->name;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig() {
        return AppConfig::fromWebApp($this->webApp);
    }

    /**
     * {@inheritDoc}
     */
    public function getRuntimeOperations(): array
    {
        return $this->webApp->getRuntimeOperations();
    }
}
