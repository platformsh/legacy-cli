<?php

declare(strict_types=1);

namespace Platformsh\Cli\Model\RemoteContainer;

use Platformsh\Cli\Model\AppConfig;
use Platformsh\Client\Model\Environment;

/**
 * Represents a broken environment, which may still allow SSH.
 *
 * Used when an environment doesn't have a working deployments API, due to
 * application validation errors on the server side.
 */
readonly class BrokenEnv implements RemoteContainerInterface
{
    public function __construct(private Environment $environment, private string $appName) {}

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
    public function getName(): string
    {
        return $this->appName;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig(): AppConfig
    {
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
