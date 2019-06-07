<?php

namespace Platformsh\Cli\Model\RemoteContainer;

use Platformsh\Cli\Model\AppConfig;
use Platformsh\Client\Model\Environment;

class LegacyEnv implements RemoteContainerInterface
{
    private $environment;

    /**
     * @param \Platformsh\Client\Model\Environment       $environment
     */
    public function __construct(Environment $environment) {
        $this->environment = $environment;
    }

    /**
     * {@inheritdoc}
     */
    public function getSshUrl()
    {
        return $this->environment->getSshUrl();
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return $this->environment->id;
    }

    /**
     * {@inheritdoc}
     */
    public function getType()
    {
        return 'environment';
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig() {
        return new AppConfig([]);
    }

    /**
     * {@inheritdoc}
     */
    public function getMounts() {
        return [];
    }
}
