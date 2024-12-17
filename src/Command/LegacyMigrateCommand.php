<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Exception\RootNotFoundException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'legacy-migrate', description: 'Migrate from the legacy file structure')]
class LegacyMigrateCommand extends CommandBase
{

    public function __construct(private readonly Config $config, private readonly Filesystem $filesystem, private readonly LocalProject $localProject, private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this
            ->addOption('no-backup', null, InputOption::VALUE_NONE, 'Do not create a backup of the project.');
        $cliName = $this->config->get('application.name');
        $localDir = $this->config->get('local.local_dir');
        $this->setHelp(<<<EOF
Before version 3.x, the {$cliName} required a project to have a "repository"
directory containing the Git repository, "builds", "shared" and others. From
version 3, the Git repository itself is treated as the project. Metadata is
stored inside the repository (in {$localDir}) and ignored by Git.

This command will migrate from the old file structure to the new one.
EOF
        );
    }

    public function isHidden(): bool
    {
        if (parent::isHidden()) {
            return true;
        }
        $localProject = $this->localProject;

        return !$localProject->getLegacyProjectRoot();
    }

    public function isEnabled(): bool
    {
        return $this->config->has('local.project_config_legacy') && parent::isEnabled();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $localProject = $this->localProject;
        $legacyRoot = $localProject->getLegacyProjectRoot();
        if (!$legacyRoot) {
            if ($this->selector->getProjectRoot()) {
                $this->stdErr->writeln(sprintf(
                    'This project is already compatible with the %s version 3.x.',
                    $this->config->get('application.name')
                ));

                return 0;
            }
            throw new RootNotFoundException();
        }

        $cwd = getcwd();

        $fs = $this->filesystem;

        $repositoryDir = $legacyRoot . '/repository';
        if (!is_dir($repositoryDir)) {
            $this->stdErr->writeln('Directory not found: <error>' . $repositoryDir . '</error>');

            return 1;
        } elseif (!is_dir($repositoryDir . '/.git')) {
            $this->stdErr->writeln('Not a Git repository: <error>' . $repositoryDir . '</error>');

            return 1;
        }

        if (!$input->getOption('no-backup')) {
            $backup = rtrim($legacyRoot, '\\/') . '-backup.tar.gz';
            if (file_exists($backup)) {
                $this->stdErr->writeln('Backup destination already exists: <error>' . $backup . '</error>');
                $this->stdErr->writeln(
                    'Move (or delete) the backup, then run <comment>'
                    . $this->config->get('application.executable')
                    . ' legacy-migrate</comment> to continue.'
                );

                return 1;
            }

            $this->stdErr->writeln('Backing up entire project to: ' . $backup);
            $fs->archiveDir($legacyRoot, $backup);
        }

        $this->stdErr->writeln('Creating directory: ' . $this->config->get('local.local_dir'));
        $localProject->ensureLocalDir($repositoryDir);

        if (file_exists($legacyRoot . '/shared')) {
            $this->stdErr->writeln('Moving "shared" directory.');
            if (is_dir($repositoryDir . '/' . $this->config->get('local.shared_dir'))) {
                $fs->copyAll($legacyRoot . '/shared', $repositoryDir . '/' . $this->config->get('local.shared_dir'));
                $fs->remove($legacyRoot . '/shared');
            } else {
                rename($legacyRoot . '/shared', $repositoryDir . '/' . $this->config->get('local.shared_dir'));
            }
        }

        if (file_exists($legacyRoot . '/' . $this->config->get('local.project_config_legacy'))) {
            $this->stdErr->writeln('Moving project config file.');
            $fs->copy(
                $legacyRoot . '/' . $this->config->get('local.project_config_legacy'),
                $legacyRoot . '/' . $this->config->get('local.project_config')
            );
            $fs->remove($legacyRoot . '/' . $this->config->get('local.project_config_legacy'));
        }

        if (file_exists($legacyRoot . '/.build-archives')) {
            $this->stdErr->writeln('Removing old build archives.');
            $fs->remove($legacyRoot . '/.build-archives');
        }

        if (file_exists($legacyRoot . '/builds')) {
            $this->stdErr->writeln('Removing old builds.');
            $fs->remove($legacyRoot . '/builds');
        }

        if (is_link($legacyRoot . '/www')) {
            $this->stdErr->writeln('Removing old "www" symlink.');
            $fs->remove($legacyRoot . '/www');
            $this->stdErr->writeln('');
            $this->stdErr->writeln('After running <comment>' . $this->config->get('application.executable') . ' build</comment>, your web root will be at: <comment>' . $this->config->getWithDefault('local.web_root', '_www') . '</comment>');
            $this->stdErr->writeln('You may need to update your local web server configuration.');
            $this->stdErr->writeln('');
        }

        $this->stdErr->writeln('Moving repository to be the new project root (this could take some time)...');
        $fs->copyAll($repositoryDir, $legacyRoot, [], true);
        $fs->remove($repositoryDir);

        if (!is_dir($legacyRoot . '/.git')) {
            $this->stdErr->writeln('Error: not found: <error>' . $legacyRoot . '/.git</error>');

            return 1;
        } elseif (file_exists($legacyRoot . '/' . $this->config->get('local.project_config_legacy'))) {
            $this->stdErr->writeln(sprintf(
                'Error: file still exists: <error>%s</error>',
                $legacyRoot . '/' . $this->config->get('local.project_config_legacy')
            ));

            return 1;
        }

        $this->stdErr->writeln("\n<info>Migration complete</info>\n");

        if (str_starts_with($cwd, $repositoryDir)) {
            $this->stdErr->writeln('Type this to refresh your shell:');
            $this->stdErr->writeln('    <comment>cd ' . $legacyRoot . '</comment>');
        }

        return 0;
    }
}
