<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Local;

use Platformsh\Cli\Selector\Selector;
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
    public function __construct(private readonly Config $config, private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this
            ->addArgument('subdir', InputArgument::OPTIONAL, "The subdirectory to find ('local', 'web' or 'shared')");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = $this->selector->getProjectRoot();
        if (!$projectRoot) {
            throw new RootNotFoundException();
        }

        $dir = $projectRoot;

        $subDirs = [
            'builds' => $this->config->getStr('local.build_dir'),
            'local' => $this->config->getStr('local.local_dir'),
            'shared' => $this->config->getStr('local.shared_dir'),
            'web' => $this->config->getStr('local.web_root'),
            'web_root' => $this->config->getStr('local.web_root'),
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
