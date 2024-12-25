<?php

declare(strict_types=1);

namespace Platformsh\Cli\Local\DependencyManager;

use Platformsh\Cli\Service\Shell;

class Pip extends DependencyManagerBase
{
    public function __construct(Shell $shell, private readonly string $stack)
    {
        parent::__construct($shell);
    }

    /**
     * {@inheritdoc}
     */
    public function getInstallHelp(): string
    {
        return 'See https://pip.pypa.io/en/stable/installing/ for installation instructions.';
    }

    /**
     * {@inheritdoc}
     */
    public function getBinPaths($prefix): array
    {
        return [$prefix . '/bin'];
    }

    /**
     * {@inheritdoc}
     */
    public function getCommandName(): string
    {
        $commands = ['pip', 'pip3', 'pip2'];
        if ($this->stack === 'python3') {
            $commands = ['pip3', 'pip'];
        } elseif ($this->stack === 'python2') {
            $commands = ['pip2', 'pip'];
        }
        foreach ($commands as $command) {
            if ($this->shell->commandExists($command)) {
                return $command;
            }
        }

        return 'pip';
    }

    /**
     * {@inheritdoc}
     */
    public function install($path, array $dependencies, $global = false): void
    {
        file_put_contents($path . '/requirements.txt', $this->formatRequirementsTxt($dependencies));
        $command = $this->getCommandName() . ' install --requirement=requirements.txt';
        if (!$global) {
            $command .= ' --prefix=.';
        }
        $this->runCommand($command, $path);
    }

    /**
     * {@inheritdoc}
     */
    public function getEnvVars($path): array
    {
        $envVars = [];

        // The PYTHONPATH needs to be set as something like
        // "lib/python2.7/site-packages". So here we are scanning "lib" to find
        // the correct subdirectory.
        if (file_exists($path . '/lib')) {
            $subdirectories = scandir($path . '/lib') ?: [];
            foreach ($subdirectories as $subdirectory) {
                if (!str_starts_with($subdirectory, '.')) {
                    $envVars['PYTHONPATH'] = $path . '/lib/' . $subdirectory . '/site-packages';
                    break;
                }
            }
        }

        return $envVars;
    }

    /**
     * @param array<string, mixed> $dependencies
     *
     * @return string
     */
    private function formatRequirementsTxt(array $dependencies): string
    {
        $lines = [];
        foreach ($dependencies as $package => $version) {
            if (in_array($version[0], ['<', '!', '>', '='])) {
                $lines[] = sprintf('%s%s', $package, $version);
            } elseif ($version === '*') {
                $lines[] = $package;
            } else {
                $lines[] = sprintf('%s==%s', $package, $version);
            }
        }

        return implode("\n", $lines);
    }
}
