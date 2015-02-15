<?php

namespace CommerceGuys\Platform\Cli\Local\Toolstack;

class Symfony extends Composer
{

    public function getKey() {
        return 'php:symfony';
    }

    public function detect($appRoot)
    {
        if (file_exists($appRoot . '/composer.json')) {
            $json = file_get_contents($appRoot . '/composer.json');
            $composer_json = json_decode($json);
            if (property_exists($composer_json->require, "symfony/symfony")) {
              return TRUE; // @todo: Find a better way to test for Symfony. Some projects do not have this dep.
            }
        }
        
        return FALSE;
    }

    public function install()
    {
        $configDir = $this->buildDir . '/app/config';
        $sharedDir = $this->getSharedDir();
        $symfonyResources = CLI_ROOT . '/resources/symfony';

        // Create and symlink configuration files.
        // @todo are these actually useful?
        foreach (array('config_dev.yml', 'routing_dev.yml') as $file) {
            $this->fsHelper->copy("$symfonyResources/$file", "$sharedDir/$file");
            $this->fsHelper->symLink("$sharedDir/$file", "$configDir/$file");
        }
    }

}
