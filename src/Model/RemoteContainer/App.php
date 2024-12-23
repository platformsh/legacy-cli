<?php

declare(strict_types=1);

namespace Platformsh\Cli\Model\RemoteContainer;

use Platformsh\Cli\Model\AppConfig;
use Platformsh\Client\Model\Deployment\WebApp;
use Platformsh\Client\Model\Environment;

readonly class App implements RemoteContainerInterface
{
    public function __construct(private WebApp $webApp, private Environment $environment) {}

    public function getSshUrl($instance = ''): string
    {
        return $this->environment->getSshUrl($this->webApp->name, $instance);
    }

    public function getName(): string
    {
        return $this->webApp->name;
    }

    public function getConfig(): AppConfig
    {
        return AppConfig::fromWebApp($this->webApp);
    }

    public function getRuntimeOperations(): array
    {
        return $this->webApp->getRuntimeOperations();
    }
}
