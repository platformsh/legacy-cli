<?php

namespace Platformsh\Cli\Local\DependencyManager;

class Yarn extends DependencyManagerBase
{
    protected $globalList;

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
    public function getCommandName($global = false)
    {
        return !$global && $this->shell->commandExists('yarn') ? 'yarn' : 'npm';
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
    public function install($path, array $dependencies, $global = false)
    {
        if ($global) {
            $this->installGlobal($dependencies);
            return;
        }
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

    /**
     * Install dependencies globally.
     *
     * @param array $dependencies
     */
    private function installGlobal(array $dependencies)
    {
        foreach ($dependencies as $package => $version) {
            if ($this->isInstalledGlobally($package, $version)) {
                continue;
            }
            $arg = escapeshellarg($version === '*' ? $package : $package . ':' . $version);
            $command = 'npm install --global ' . $arg;
            $this->runCommand($command);
        }
    }

    /**
     * @param string $package
     * @param string $version
     *
     * @return bool
     */
    private function isInstalledGlobally($package, $version)
    {
        if (!isset($this->globalList)) {
            $this->globalList = $this->shell->execute(
                ['npm', 'ls', '--global', '--no-progress', '--depth=0']
            );
        }
        $needle = $version === '*' ? $package : $package . '@' . $version;

        return $this->globalList && strpos($this->globalList, $needle) !== false;
    }
}
