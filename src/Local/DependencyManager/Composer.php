<?php

declare(strict_types=1);

namespace Platformsh\Cli\Local\DependencyManager;

class Composer extends DependencyManagerBase
{
    protected string $command = 'composer';

    /**
     * {@inheritdoc}
     */
    public function getInstallHelp(): string
    {
        return 'See https://getcomposer.org/ for installation instructions.';
    }

    /**
     * {@inheritdoc}
     */
    public function getBinPaths($prefix): array
    {
        return [$prefix . '/vendor/bin'];
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
        $composerJson = $path . '/composer.json';
        $contents = file_exists($composerJson) ? file_get_contents($composerJson) : null;
        $config = [];
        if (isset($dependencies['require']) || isset($dependencies['repositories'])) {
            if (isset($dependencies['require'])) {
                $config['require'] = $dependencies['require'];
            }
            if (isset($dependencies['repositories'])) {
                $config['repositories'] = $dependencies['repositories'];
            }
        } else {
            $config['require'] = $dependencies;
        }
        $newContents = \json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($contents !== $newContents) {
            file_put_contents($composerJson, $newContents);
            if (file_exists($path . '/composer.lock')) {
                unlink($path . '/composer.lock');
            }
        }

        $this->runCommand('composer install --no-progress --prefer-dist --optimize-autoloader --no-interaction', $path);
    }

    /**
     * Install dependencies globally.
     *
     * @param array<string, mixed> $dependencies
     */
    private function installGlobal(array $dependencies): void
    {
        $requirements = [];
        foreach ($dependencies as $package => $version) {
            $requirements[] = $version === '*' ? $package : $package . ':' . $version;
        }
        $this->runCommand(
            'composer global require '
            . '--no-progress --prefer-dist --optimize-autoloader --no-interaction '
            . implode(' ', array_map('escapeshellarg', $requirements)),
        );
    }
}
