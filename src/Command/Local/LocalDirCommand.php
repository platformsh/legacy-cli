<?php
namespace Platformsh\Cli\Command\Local;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'local:dir', description: 'Find the local project root', aliases: ['dir'])]
class LocalDirCommand extends CommandBase
{
    protected $local = true;
    public function __construct(private readonly Config $config)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->addArgument('subdir', InputArgument::OPTIONAL, "The subdirectory to find ('local', 'web' or 'shared')");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            throw new RootNotFoundException();
        }

        $dir = $projectRoot;

        $subDirs = [
            'builds' => $this->config->get('local.build_dir'),
            'local' => $this->config->get('local.local_dir'),
            'shared' => $this->config->get('local.shared_dir'),
            'web' => $this->config->getWithDefault('local.web_root', '_www'),
            'web_root' => $this->config->getWithDefault('local.web_root', '_www'),
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
