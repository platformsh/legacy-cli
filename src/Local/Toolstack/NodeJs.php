<?php

namespace Platformsh\Cli\Local\Toolstack;

use Platformsh\Cli\Exception\DependencyMissingException;
use Symfony\Component\Console\Output\OutputInterface;

class NodeJs extends ToolstackBase
{
    public function getKey()
    {
        return 'nodejs:default';
    }

    public function detect($appRoot)
    {
        // Refuse to detect automatically.
        return false;
    }

    public function build()
    {
        $this->buildInPlace = true;

        $buildDir = $this->getBuildDir();

        if ($this->copy) {
            if (!file_exists($this->appRoot . '/' . $this->documentRoot)) {
                $buildDir = $this->getWebRoot();
            }
            $this->fsHelper->copyAll($this->appRoot, $buildDir);
        }

        if (file_exists($buildDir . '/package.json')) {
            $this->output->writeln("Found a package.json file, installing dependencies");

            if (!$this->shellHelper->commandExists('npm')) {
                throw new DependencyMissingException('npm is not installed');
            }

            $npm = $this->shellHelper->resolveCommand('npm');
            $npmArgs = [$npm];
            if (!empty($this->settings['verbosity']) && $this->settings['verbosity'] >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                $npmArgs[] = '--loglevel=verbose';
            }

            $pruneArgs = $npmArgs;
            $pruneArgs[] = 'prune';
            $this->shellHelper->execute($pruneArgs, $buildDir, true, false);

            $installArgs = $npmArgs;
            $installArgs[] = 'install';
            $this->shellHelper->execute($installArgs, $buildDir, true, false);
        }

        $this->processSpecialDestinations();
    }

    public function install()
    {
        $this->copyGitIgnore('gitignore-nodejs');
    }
}
