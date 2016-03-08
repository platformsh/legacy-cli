<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Exception\RootNotFoundException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LegacyMigrateCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('legacy-migrate')
            ->setDescription('Migrate from the legacy file structure');
        $cliName = CLI_NAME;
        $localDir = CLI_LOCAL_DIR;
        $this->setHelp(<<<EOF
Before version 3.x, the {$cliName} required a project to have a "repository"
directory containing the Git repository, "builds", "shared" and others. From
version 3, the Git repository itself is treated as the project. Metadata is
stored inside the repository (in {$localDir}) and ignored by Git.

This command will migrate from the old file structure to the new one.
EOF
        );
    }

    public function hideInList()
    {
        return $this->localProject->getLegacyProjectRoot() ? false : true;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $legacyRoot = $this->localProject->getLegacyProjectRoot();
        if (!$legacyRoot) {
            if ($this->getProjectRoot()) {
                $this->stdErr->writeln('This project is already compatible with the ' . CLI_NAME . ' version 3.x.');

                return 0;
            }
            throw new RootNotFoundException();
        }

        $cwd = getcwd();

        /** @var \Platformsh\Cli\Helper\FilesystemHelper $fsHelper */
        $fsHelper = $this->getHelper('fs');
        /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');

        $repositoryDir = $legacyRoot . '/repository';
        if (!is_dir($repositoryDir)) {
            $this->stdErr->writeln('Directory not found: <error>' . $repositoryDir . '</error>');
            return 1;
        }
        elseif (!is_dir($repositoryDir . '/.git')) {
            $this->stdErr->writeln('Not a Git repository: <error>' . $repositoryDir . '</error>');
            return 1;
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
            if (is_dir($repositoryDir . '/' . CLI_LOCAL_SHARED_DIR)) {
                $fsHelper->copyAll($legacyRoot . '/shared', $repositoryDir . '/' . CLI_LOCAL_SHARED_DIR);
                $fsHelper->remove($legacyRoot . '/shared');
            }
            else {
                rename($legacyRoot . '/shared', $repositoryDir . '/' . CLI_LOCAL_SHARED_DIR);
            }
        }

        if (file_exists($legacyRoot . '/.build-archives')) {
            $this->stdErr->writeln('Moving ".build-archives" directory');
            if (is_dir($repositoryDir . '/' . CLI_LOCAL_ARCHIVE_DIR)) {
                $fsHelper->copyAll($legacyRoot . '/.build-archives', $repositoryDir . '/' . CLI_LOCAL_ARCHIVE_DIR);
                $fsHelper->remove($legacyRoot . '/shared');
            }
            else {
                rename($legacyRoot . '/.build-archives', $repositoryDir . '/' . CLI_LOCAL_ARCHIVE_DIR);
            }
        }

        if (file_exists($legacyRoot . '/www')) {
            $fsHelper->remove($legacyRoot . '/www');
        }

        $this->localProject->writeGitExclude($repositoryDir);

        $this->stdErr->writeln('Moving repository to be the new project root');
        $fsHelper->copyAll($repositoryDir, $legacyRoot, []);
        $fsHelper->remove($repositoryDir);

        if (file_exists($legacyRoot . '/' . CLI_LOCAL_PROJECT_CONFIG_LEGACY)) {
            $fsHelper->copy($legacyRoot . '/' . CLI_LOCAL_PROJECT_CONFIG_LEGACY, $legacyRoot . '/' . CLI_LOCAL_PROJECT_CONFIG);
            $fsHelper->remove($legacyRoot . '/' . CLI_LOCAL_PROJECT_CONFIG_LEGACY);
        }

        if (!is_dir($legacyRoot . '/.git')) {
            $this->stdErr->writeln('Error: not found: <error>' . $legacyRoot . '/.git</error>');
            return 1;
        }
        elseif (file_exists($legacyRoot . '/' . CLI_LOCAL_PROJECT_CONFIG_LEGACY)) {
            $this->stdErr->writeln('Error: file still exists: <error>' . $legacyRoot . '/' . CLI_LOCAL_PROJECT_CONFIG_LEGACY . '</error>');
            return 1;
        }

        $this->stdErr->writeln('Migration complete');

        if (strpos($cwd, $repositoryDir) === 0) {
            $this->stdErr->writeln('Type this to refresh your shell:');
            $this->stdErr->writeln('    cd ' . $legacyRoot);
        }

        return 0;
    }
}
