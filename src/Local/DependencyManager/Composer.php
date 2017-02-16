<?php

namespace Platformsh\Cli\Local\DependencyManager;

class Composer extends DependencyManagerBase
{
    protected $command = 'composer';

    /**
     * {@inheritdoc}
     */
    public function getInstallHelp()
    {
        return 'See https://getcomposer.org/ for installation instructions.';
    }

    /**
     * {@inheritdoc}
     */
    public function getBinPaths($prefix)
    {
        return [$prefix . '/vendor/bin'];
    }

    /**
     * @param string $path
     *
     * @param array  $dependencies
     */
    public function install($path, array $dependencies)
    {
        $composerJson = $path . '/composer.json';
        $contents = file_exists($composerJson) ? file_get_contents($composerJson) : null;
        $newContents = json_encode(['require' => $dependencies]);
        if ($contents !== $newContents) {
            file_put_contents($composerJson, $newContents);
            if (file_exists($path . '/composer.lock')) {
                unlink($path . '/composer.lock');
            }
        }
        $this->runCommand('composer install --no-progress --prefer-dist --optimize-autoloader --no-interaction', $path);
    }
}
