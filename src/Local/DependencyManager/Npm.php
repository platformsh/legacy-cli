<?php

declare(strict_types=1);

namespace Platformsh\Cli\Local\DependencyManager;

class Npm extends DependencyManagerBase
{
    protected string $command = 'npm';

    private ?string $globalList = null;

    /**
     * {@inheritdoc}
     */
    public function getInstallHelp(): string
    {
        return 'See https://docs.npmjs.com/getting-started/installing-node for installation instructions.';
    }

    /**
     * {@inheritdoc}
     */
    public function getBinPaths($prefix): array
    {
        return [$prefix . '/node_modules/.bin'];
    }

    /**
     * {@inheritdoc}
     */
    public function install($path, array $dependencies, $global = false): void
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
        }
        $this->runCommand('npm install --global-style', $path);
    }

    /**
     * Install dependencies globally.
     *
     * @param array<string, mixed> $dependencies
     */
    private function installGlobal(array $dependencies): void
    {
        foreach ($dependencies as $package => $version) {
            if (!$this->isInstalledGlobally($package)) {
                $arg = $version === '*' ? $package : $package . '@' . $version;
                $this->runCommand('npm install --global ' . escapeshellarg($arg));
            }
        }
    }

    /**
     * @param string $package
     *
     * @return bool
     */
    private function isInstalledGlobally(string $package): bool
    {
        if (!isset($this->globalList)) {
            $this->globalList = $this->shell->mustExecute(
                ['npm', 'ls', '--global', '--no-progress', '--depth=0'],
            );
        }

        return $this->globalList && str_contains((string) $this->globalList, $package . '@');
    }
}
