<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Local;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Service\Config;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LocalDirCommand extends CommandBase
{
    protected static $defaultName = 'local:dir|dir';
    protected static $defaultDescription = 'Find the local project root';

    private $config;
    private $localProject;

    public function __construct(Config $config, LocalProject $localProject)
    {
        $this->config = $config;
        $this->localProject = $localProject;
        parent::__construct();
    }

    protected function configure()
    {
        $this->addArgument('subdir', InputArgument::OPTIONAL, "The subdirectory to find ('local', 'web' or 'shared')");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectRoot = $this->localProject->getProjectRoot();
        if (!$projectRoot) {
            throw new RootNotFoundException();
        }

        $dir = $projectRoot;

        $subDirs = [
            'builds' => $this->config->get('local.build_dir'),
            'local' => $this->config->get('local.local_dir'),
            'shared' => $this->config->get('local.shared_dir'),
            'web' => $this->config->get('local.web_root'),
            'web_root' => $this->config->get('local.web_root'),
        ];

        $subDir = $input->getArgument('subdir');
        if ($subDir) {
            if (!isset($subDirs[$subDir])) {
                $this->stdErr->writeln("Unknown subdirectory: <error>$subDir</error>");

                return 1;
            }
            $dir .= DIRECTORY_SEPARATOR . $subDirs[$subDir];
        }

        if (!is_dir($dir)) {
            $this->stdErr->writeln("Directory not found: <error>$dir</error>");

            return 1;
        }

        $output->writeln($dir);

        return 0;
    }
}
