<?php

namespace Platformsh\Cli\Local\DependencyManager;

class Pip extends DependencyManagerBase
{
    protected $command = 'pip';

    /**
     * {@inheritdoc}
     */
    public function getInstallHelp()
    {
        return 'See https://pip.pypa.io/en/stable/installing/ for installation instructions.';
    }

    /**
     * {@inheritdoc}
     */
    public function getBinPaths($prefix)
    {
        return [$prefix . '/bin'];
    }

    /**
     * {@inheritdoc}
     */
    public function install($path, array $dependencies, $global = false)
    {
        file_put_contents($path . '/requirements.txt', $this->formatRequirementsTxt($dependencies));
        $command = 'pip install --requirement=requirements.txt';
        if (!$global) {
            $command .= ' --prefix=.';
        }
        $this->runCommand($command, $path);
    }

    /**
     * {@inheritdoc}
     */
    public function getEnvVars($path)
    {
        return ['PYTHONPATH' => $path];
    }

    /**
     * @param array $dependencies
     *
     * @return string
     */
    private function formatRequirementsTxt(array $dependencies)
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
