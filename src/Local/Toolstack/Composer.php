<?php

namespace Platformsh\Cli\Local\Toolstack;

use Symfony\Component\Console\Output\OutputInterface;

class Composer extends ToolstackBase
{
    public function getKey()
    {
        return 'php:composer';
    }

    public function detect($appRoot)
    {
        return file_exists("$appRoot/composer.json");
    }

    public function build()
    {
        $buildDir = $this->copyToBuildDir();

        // The composer.json file may not exist at this stage, if the user has
        // manually specified a Composer toolstack (e.g. php:symfony).
        if (file_exists($buildDir . '/composer.json')) {
            $this->stdErr->writeln("Found a composer.json file; installing dependencies");

            $args = [
                $this->shellHelper->resolveCommand('composer'),
                'install',
                '--no-progress',
                '--prefer-dist',
                '--optimize-autoloader',
                '--no-interaction',
                '--no-ansi',
            ];
            if ($this->stdErr->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                $args[] = '-vvv';
            } elseif ($this->stdErr->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                $args[] = '-vv';
            } elseif ($this->stdErr->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $args[] = '-v';
            }
            $this->shellHelper->execute($args, $buildDir, true, false);
        }

        $this->processSpecialDestinations();
    }

    public function install()
    {
        parent::install();
        $this->copyGitIgnore('gitignore-composer');

        if (Drupal::isDrupal($this->getWebRoot())) {
            $this->installDrupalSettingsLocal();
        }
    }
}
