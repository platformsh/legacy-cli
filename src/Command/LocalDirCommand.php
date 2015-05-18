<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\LocalProject;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LocalDirCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('local:dir')
          ->setAliases(array('dir'))
          ->setDescription('Find the local project root')
          ->addArgument('subdir', InputArgument::OPTIONAL, "The subdirectory to find ('repo', 'web', or 'shared')");
        $this->setHiddenInList();
    }

    public function isLocal()
    {
        return true;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // @todo split output into stderr and stdout

        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            throw new RootNotFoundException();
        }

        $dir = $projectRoot;

        $subDirs = array(
          'shared' => LocalProject::SHARED_DIR,
          'repo' => LocalProject::REPOSITORY_DIR,
          'repository' => LocalProject::REPOSITORY_DIR,
          'web' => LocalProject::WEB_ROOT,
          'web_root' => LocalProject::WEB_ROOT,
        );

        $subDir = $input->getArgument('subdir');
        if ($subDir) {
            if (!isset($subDirs[$subDir])) {
                $output->writeln("Unknown subdirectory: <error>$subDir</error>");

                return 1;
            }
            $dir .= '/' . $subDirs[$subDir];
        }

        if (!is_dir($dir)) {
            $output->writeln("Directory not found: <error>$dir</error>");

            return 1;
        }

        $output->writeln($dir);

        return 0;
    }
}
