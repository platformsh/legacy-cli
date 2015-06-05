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
        $this->leaveInPlace = true;

        // The composer.json file may not exist at this stage, if the user has
        // manually specified a Composer toolstack (e.g. php:symfony).
        if (file_exists($this->appRoot . '/composer.json')) {
            $this->output->writeln("Found a composer.json file; installing dependencies");

            $args = array(
              $this->getComposerExecutable(),
              'install',
              '--no-progress',
              '--no-interaction',
            );
            $this->shellHelper->execute($args, $this->appRoot, true, false);
        }
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
        if (strpos(PHP_OS, 'WIN') !== false && ($fullPath = exec('where composer'))) {
            $executable = $fullPath;
        }

        return $executable;
    }
}
