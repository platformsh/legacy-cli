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
        $this->buildDir = $this->appRoot;
        if ($this->documentRoot === '/public') {
            $this->documentRoot = '/';
        }

        $this->specialDestinations = array();
        $this->preventArchive = true;

        $this->output->writeln("Found a composer.json file; installing dependencies");

        $args = array('composer', 'install', '--no-progress', '--no-interaction');
        $this->shellHelper->execute($args, $this->buildDir, true, false);
    }

    public function install()
    {
        $this->copyGitIgnore('gitignore-composer');
    }

}
