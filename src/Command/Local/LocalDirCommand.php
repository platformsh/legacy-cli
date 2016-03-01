<?php
namespace Platformsh\Cli\Command\Local;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LocalDirCommand extends CommandBase
{
    protected $hiddenInList = true;
    protected $local = true;

    protected function configure()
    {
        $this
            ->setName('local:dir')
            ->setAliases(['dir'])
            ->setDescription('Find the local project root')
            ->addArgument('subdir', InputArgument::OPTIONAL, "The subdirectory to find ('repo', 'web', or 'shared')");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            throw new RootNotFoundException();
        }

        $dir = $projectRoot;

        $subDirs = [
            'shared' => CLI_LOCAL_SHARED_DIR,
            'repo' => CLI_LOCAL_REPOSITORY_DIR,
            'repository' => CLI_LOCAL_REPOSITORY_DIR,
            'web' => CLI_LOCAL_WEB_ROOT,
            'web_root' => CLI_LOCAL_WEB_ROOT,
        ];

        $subDir = $input->getArgument('subdir');
        if ($subDir) {
            if (!isset($subDirs[$subDir])) {
                $this->stdErr->writeln("Unknown subdirectory: <error>$subDir</error>");

                return 1;
            }
            $dir .= '/' . $subDirs[$subDir];
        }

        if (!is_dir($dir)) {
            $this->stdErr->writeln("Directory not found: <error>$dir</error>");

            return 1;
        }

        $output->writeln($dir);

        return 0;
    }
}
