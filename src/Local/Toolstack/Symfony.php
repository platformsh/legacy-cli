<?php

namespace CommerceGuys\Platform\Cli\Local\Toolstack;

class Symfony extends ToolstackBase
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

    public function build()
    {
        $buildDir = $this->buildDir;

        if (!file_exists($this->appRoot . '/composer.json')) {
            throw new \Exception("Couldn't find a composer.json in the directory.");
        }

        mkdir($buildDir);
        $this->fsHelper->copy($this->appRoot, $buildDir);
        if (is_dir($buildDir)) {
            $args = array('composer', 'install', '--no-progress', '--no-interaction', '--working-dir', $buildDir);
            $this->shellHelper->execute($args, $buildDir, true, false);
        }
        else {
            throw new \Exception("Couldn't create build directory");
        }
    }

    public function install() {
        $buildDir = $this->buildDir;

        // The build has been done, create a config_dev.yml if it is missing.
        if (is_dir($buildDir) && file_exists($buildDir . '/app/config')) {
            if (!file_exists($buildDir . '/app/config/config_dev.yml')) {
                // Create the config_dev.yml file.
                copy(CLI_ROOT . '/resources/symfony/config_dev.yml', $buildDir . '/app/config/');
            }
            if (!file_exists($buildDir . '/app/config/routing_dev.yml')) {
                // Create the routing_dev.yml file.
                copy(CLI_ROOT . '/resources/symfony/routing_dev.yml', $buildDir . '/app/config/');
            }
        }

        $this->symLinkSpecialDestinations();

        // Point www to the latest build.
        $this->fsHelper->symLink($buildDir, $this->projectRoot . '/www');
    }
}
