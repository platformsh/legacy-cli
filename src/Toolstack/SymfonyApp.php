<?php

namespace CommerceGuys\Platform\Cli\Toolstack;

use CommerceGuys\Platform\Cli;
use Symfony\Component\Console;
use Symfony\Component\Finder\Finder;

class SymfonyApp extends PhpApp implements LocalBuildInterface
{

    public static function detect($appRoot, $settings)
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
        $buildDir = $this->absBuildDir;
        $repositoryDir = $this->appRoot;
        $projectRoot = $this->settings['projectRoot'];

        if (file_exists($repositoryDir . '/composer.json')) {
            $projectComposer = $repositoryDir . '/composer.json';
        }
        else {
            throw new \Exception("Couldn't find a composer.json in the repository.");
        }
        mkdir($buildDir);
        $this->copy($repositoryDir, $buildDir);
        if (is_dir($buildDir)) {
            chdir($buildDir);
            shell_exec("composer install --no-progress --no-interaction  --working-dir $buildDir");
        }
        else {
          throw new \Exception("Couldn't create build directory");
        }
        // The build has been done, create a config_dev.yml if it is missing.
        if (is_dir($buildDir) && !file_exists($buildDir . '/app/config/config_dev.yml')) {
            // Create the config_dev.yml file.
            copy(CLI_ROOT . '/resources/symfony/config_dev.yml', $buildDir . '/app/config/config_dev.yml');
        }
        if (is_dir($buildDir) && !file_exists($buildDir . '/app/config/routing_dev.yml')) {
            // Create the routing_dev.yml file.
            copy(CLI_ROOT . '/resources/symfony/routing_dev.yml', $buildDir . '/app/config/routing_dev.yml');
        }

        // Point www to the latest build.
        $wwwLink = $projectRoot . '/www';
        if (file_exists($wwwLink) || is_link($wwwLink)) {
            // @todo Windows might need rmdir instead of unlink.
            unlink($wwwLink);
        }
        symlink($this->absoluteLinks ? $this->absBuildDir : $this->relBuildDir, $wwwLink);

    }
}
