<?php

namespace Platformsh\Cli\Local\Toolstack;

class Composer extends ToolstackBase
{

    public function detect($appRoot)
    {
        return file_exists("$appRoot/composer.json");
    }

    public function build()
    {
        $this->buildInPlace = true;

        if ($this->copy) {
            $buildDir = $this->getWebRoot();
            $this->fsHelper->copyAll($this->appRoot, $this->getWebRoot());
        }
        else {
            $buildDir = $this->appRoot;
        }

        // The composer.json file may not exist at this stage, if the user has
        // manually specified a Composer toolstack (e.g. php:symfony).
        if (file_exists($buildDir . '/composer.json')) {
            $this->output->writeln("Found a composer.json file; installing dependencies");

            $args = array(
              $this->getComposerExecutable(),
              'install',
              '--no-progress',
              '--prefer-dist',
              '--optimize-autoloader',
              '--no-interaction',
              '--no-ansi',
            );
            $this->shellHelper->execute($args, $buildDir, true, false);
        }

        $this->processSpecialDestinations();
    }

    public function install()
    {
        $this->copyGitIgnore('gitignore-composer');
    }

    /**
     * Get the name of the Composer executable.
     *
     * @return string
     */
    protected function getComposerExecutable()
    {
        $executable = 'composer';
        if (strpos(PHP_OS, 'WIN') !== false && ($fullPath = shell_exec('where composer'))) {
            $executable = $fullPath;
        }

        return $executable;
    }
}
