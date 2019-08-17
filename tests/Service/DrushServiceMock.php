<?php

namespace Platformsh\Cli\Tests\Service;

use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Drush;
use Platformsh\Client\Model\Environment;

class DrushServiceMock extends Drush
{
    public function __construct()
    {
        $config = new Config();
        $config->override('service.app_config_file', '_platform.app.yaml');

        parent::__construct($config);
    }

    /**
     * @param Environment $environment
     * @param LocalApplication $app
     *
     * @return string|null
     */
    public function getSiteUrl(Environment $environment, LocalApplication $app)
    {
        return $environment->getPublicUrl();
    }
}
