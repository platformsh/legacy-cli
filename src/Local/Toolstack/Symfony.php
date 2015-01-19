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
        $this->fsHelper->copyAll($this->appRoot, $buildDir);

        $args = array('composer', 'install', '--no-progress', '--no-interaction', '--working-dir', $buildDir);
        $this->shellHelper->execute($args, $buildDir, true);

        $this->symLinkSpecialDestinations();
    }

    public function install()
    {
        $configDir = $this->buildDir . '/app/config';
        $sharedDir = $this->getSharedDir();
        $symfonyResources = CLI_ROOT . '/resources/symfony';

        // Create and symlink configuration files.
        foreach (array('config_dev.yml', 'routing_dev.yml') as $file) {
            $this->fsHelper->copy("$symfonyResources/$file", "$sharedDir/$file");
            $this->fsHelper->symLink("$sharedDir/$file", "$configDir/$file");
        }
    }

}
