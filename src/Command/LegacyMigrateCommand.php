<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LegacyMigrateCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('legacy-migrate')
            ->setDescription('Migrate from the legacy file structure');
    }

    public function hideInList()
    {
        return $this->localProject->getLegacyProjectRoot() ? false : true;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $legacyRoot = $this->localProject->getLegacyProjectRoot();
        if (!$legacyRoot) {
            $this->stdErr->writeln('Legacy project root not found.');
            return 1;
        }

        $cwd = getcwd();

        /** @var \Platformsh\Cli\Helper\FilesystemHelper $fsHelper */
        $fsHelper = $this->getHelper('fs');
        /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');

        $repositoryDir = $legacyRoot . '/repository';
        if (!is_dir($repositoryDir)) {
            throw new \RuntimeException('Directory not found: ' . $repositoryDir);
        }
        elseif (!is_dir($repositoryDir . '/.git')) {
            throw new \RuntimeException('Not a Git repository: ' . $repositoryDir);
        }

        if (file_exists($legacyRoot . '/builds')) {
            if (glob($legacyRoot . '/builds/*') && !$questionHelper->confirm("This will remove the old 'builds' directory. Continue?", $input, $this->stdErr)) {
                return 1;
            }
            $this->stdErr->writeln('Removing old "builds" directory');
            $fsHelper->remove($legacyRoot . '/builds');
        }

        $this->localProject->ensureLocalDir($repositoryDir);

        if (file_exists($legacyRoot . '/shared')) {
            $this->stdErr->writeln('Moving "shared" directory');
            if (is_dir($repositoryDir . '/.platform/local/shared')) {
                $fsHelper->copyAll($legacyRoot . '/shared', $repositoryDir . '/.platform/local/shared');
                $fsHelper->remove($legacyRoot . '/shared');
            }
            else {
                rename($legacyRoot . '/shared', $repositoryDir . '/.platform/local/shared');
            }
        }

        if (file_exists($legacyRoot . '/.build-archives')) {
            $this->stdErr->writeln('Moving ".build-archives" directory');
            if (is_dir($repositoryDir . '/.platform/local/.build-archives')) {
                $fsHelper->copyAll($legacyRoot . '/.build-archives', $repositoryDir . '/.platform/local/.build-archives');
                $fsHelper->remove($legacyRoot . '/shared');
            }
            else {
                rename($legacyRoot . '/.build-archives', $repositoryDir . '/.platform/local/.build-archives');
            }
        }

        if (file_exists($legacyRoot . '/www')) {
            $fsHelper->remove($legacyRoot . '/www');
        }

        $this->localProject->writeGitExclude($repositoryDir);

        $this->stdErr->writeln('Moving repository to be the new project root');
        $fsHelper->copyAll($repositoryDir, $legacyRoot, []);
        $fsHelper->remove($repositoryDir);

        if (file_exists($legacyRoot . '/.platform-project')) {
            $fsHelper->copy($legacyRoot . '/.platform-project', $legacyRoot . '/.platform/local/project.yaml');
            $fsHelper->remove($legacyRoot . '/.platform-project');
        }

        if ($cwd !== $legacyRoot) {
            $this->stdErr->writeln('Type this to refresh your shell:');
            $this->stdErr->writeln('    cd ' . $legacyRoot);
        }

        exit;
    }
}
