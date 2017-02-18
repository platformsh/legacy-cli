<?php

namespace Platformsh\Cli\Local\DependencyManager;

class Yarn extends DependencyManagerBase
{
    /**
     * {@inheritdoc}
     */
    public function getInstallHelp()
    {
        return 'See https://yarnpkg.com/en/docs/install for installation instructions.';
    }

    /**
     * {@inheritdoc}
     */
    public function getCommandName()
    {
        return $this->shell->commandExists('yarn') ? 'yarn' : 'npm';
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable()
    {
        return $this->shell->commandExists('yarn') || $this->shell->commandExists('npm');
    }

    /**
     * {@inheritdoc}
     */
    public function getBinPaths($prefix)
    {
        return [$prefix . '/node_modules/.bin'];
    }

    /**
     * {@inheritdoc}
     */
    public function install($path, array $dependencies)
    {
        $packageJsonFile = $path . '/package.json';
        $packageJson = json_encode([
            'name' => 'temporary-build-dependencies',
            'version' => '1.0.0',
            'private' => true,
            'dependencies' => $dependencies,
        ], JSON_PRETTY_PRINT);
        $current = file_exists($packageJsonFile) ? file_get_contents($packageJsonFile) : false;
        if ($current !== $packageJson) {
            file_put_contents($packageJsonFile, $packageJson);
            if (file_exists($path . '/yarn.lock')) {
                unlink($path . '/yarn.lock');
            }
        }
        if ($this->shell->commandExists('yarn')) {
            $this->runCommand('yarn install', $path);
        } else {
            $this->runCommand('npm install --global-style', $path);
        }
    }
}
