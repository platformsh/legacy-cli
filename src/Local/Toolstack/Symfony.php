<?php

namespace Platformsh\Cli\Local\Toolstack;

class Symfony extends Composer
{

    public function getKey()
    {
        return 'php:symfony';
    }

    public function detect($appRoot)
    {
        $composerJsonFile = "$appRoot/composer.json";
        if (file_exists($composerJsonFile)) {
            $composerJson = json_decode(file_get_contents($composerJsonFile), true);

            return isset($composerJson['require']['symfony/symfony']);
        }

        return false;
    }

    public function install()
    {
        parent::install();
        $this->copyGitIgnore('symfony/gitignore-standard');
    }
}
