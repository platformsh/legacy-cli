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

        $backup = rtrim($legacyRoot, '\\/') . '-backup.tar.gz';
        if (file_exists($backup)) {
            $this->stdErr->writeln('Backup destination already exists: <error>' . $backup . '</error>');
            $this->stdErr->writeln('Move (or delete) the backup, then run <comment>' . CLI_EXECUTABLE . ' legacy-migrate</comment> to continue.');

            return 1;
        }

        $this->stdErr->writeln('Backing up entire project to: ' . $backup);
        $fsHelper->archiveDir($legacyRoot, $backup);

        $this->stdErr->writeln('Creating directory: ' . CLI_LOCAL_DIR);
        $this->localProject->ensureLocalDir($repositoryDir);

        if (file_exists($legacyRoot . '/shared')) {
            $this->stdErr->writeln('Moving "shared" directory.');
            if (is_dir($repositoryDir . '/' . CLI_LOCAL_SHARED_DIR)) {
                $fsHelper->copyAll($legacyRoot . '/shared', $repositoryDir . '/' . CLI_LOCAL_SHARED_DIR);
                $fsHelper->remove($legacyRoot . '/shared');
            }
            else {
                rename($legacyRoot . '/shared', $repositoryDir . '/' . CLI_LOCAL_SHARED_DIR);
            }
        }

        if (file_exists($legacyRoot . '/' . CLI_LOCAL_PROJECT_CONFIG_LEGACY)) {
            $this->stdErr->writeln('Moving project config file.');
            $fsHelper->copy($legacyRoot . '/' . CLI_LOCAL_PROJECT_CONFIG_LEGACY, $legacyRoot . '/' . CLI_LOCAL_PROJECT_CONFIG);
            $fsHelper->remove($legacyRoot . '/' . CLI_LOCAL_PROJECT_CONFIG_LEGACY);
        }

        if (file_exists($legacyRoot . '/.build-archives')) {
            $this->stdErr->writeln('Removing old build archives.');
            $fsHelper->remove($legacyRoot . '/.build-archives');
        }

        if (file_exists($legacyRoot . '/builds')) {
            $this->stdErr->writeln('Removing old builds.');
            $fsHelper->remove($legacyRoot . '/builds');
        }

        if (is_link($legacyRoot . '/www')) {
            $this->stdErr->writeln('Removing old "www" symlink.');
            $fsHelper->remove($legacyRoot . '/www');
        }

        $this->stdErr->writeln('Moving repository to be the new project root (this could take some time)...');
        $fsHelper->copyAll($repositoryDir, $legacyRoot, [], true);
        $fsHelper->remove($repositoryDir);

        if (!is_dir($legacyRoot . '/.git')) {
            $this->stdErr->writeln('Error: not found: <error>' . $legacyRoot . '/.git</error>');

            return 1;
        }
        elseif (file_exists($legacyRoot . '/' . CLI_LOCAL_PROJECT_CONFIG_LEGACY)) {
            $this->stdErr->writeln('Error: file still exists: <error>' . $legacyRoot . '/' . CLI_LOCAL_PROJECT_CONFIG_LEGACY . '</error>');

            return 1;
        }

        $this->stdErr->writeln("\n<info>Migration complete</info>\n");

        if (strpos($cwd, $repositoryDir) === 0) {
            $this->stdErr->writeln('Type this to refresh your shell:');
            $this->stdErr->writeln('    <comment>cd ' . $legacyRoot . '</comment>');
        }

        return 0;
    }
}
