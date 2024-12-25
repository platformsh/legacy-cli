<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Local;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Local\LocalBuild;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'local:build', description: 'Build the current project locally', aliases: ['build'])]
class LocalBuildCommand extends CommandBase
{
    public function __construct(private readonly Config $config, private readonly Filesystem $filesystem, private readonly LocalBuild $localBuild, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this
            ->addArgument('app', InputArgument::IS_ARRAY, 'Specify application(s) to build')
            ->addOption(
                'abslinks',
                'a',
                InputOption::VALUE_NONE,
                'Use absolute links',
            )
            ->addOption(
                'source',
                's',
                InputOption::VALUE_REQUIRED,
                'The source directory. Defaults to the current project root.',
            )
            ->addOption(
                'destination',
                'd',
                InputOption::VALUE_REQUIRED,
                'The destination, to which the web root of each app will be symlinked. Default: ' . $this->config->getStr('local.web_root'),
            )
            ->addOption(
                'copy',
                'c',
                InputOption::VALUE_NONE,
                'Copy to a build directory, instead of symlinking from the source',
            )
            ->addOption(
                'clone',
                null,
                InputOption::VALUE_NONE,
                'Use Git to clone the current HEAD to the build directory',
            )
            ->addOption(
                'run-deploy-hooks',
                null,
                InputOption::VALUE_NONE,
                'Run deploy and/or post_deploy hooks',
            )
            ->addOption(
                'no-clean',
                null,
                InputOption::VALUE_NONE,
                'Do not remove old builds',
            )
            ->addOption(
                'no-archive',
                null,
                InputOption::VALUE_NONE,
                'Do not create or use a build archive',
            )
            ->addOption(
                'no-backup',
                null,
                InputOption::VALUE_NONE,
                'Do not back up the previous build',
            )
            ->addOption(
                'no-cache',
                null,
                InputOption::VALUE_NONE,
                'Disable caching',
            )
            ->addOption(
                'no-build-hooks',
                null,
                InputOption::VALUE_NONE,
                'Do not run post-build hooks',
            )
            ->addOption(
                'no-deps',
                null,
                InputOption::VALUE_NONE,
                'Do not install build dependencies locally',
            )
            ->addOption(
                'working-copy',
                null,
                InputOption::VALUE_NONE,
                'Drush: use git to clone a repository of each Drupal module rather than simply downloading a version',
            )
            ->addOption(
                'concurrency',
                null,
                InputOption::VALUE_REQUIRED,
                'Drush: set the number of concurrent projects that will be processed at the same time',
                4,
            )
            ->addOption(
                'lock',
                null,
                InputOption::VALUE_NONE,
                'Drush: create or update a lock file (only available with Drush version 7+)',
            );
        $this->addExample('Build the current project');
        $this->addExample('Build the app "example" without symlinking the source files', 'example --copy');
        $this->addExample('Rebuild the current project without using an archive', '--no-archive');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = $this->selector->getProjectRoot();

        $sourceDirOption = $input->getOption('source');

        // If no project root is found, ask the user for a source directory.
        if (!$projectRoot && !$sourceDirOption && $input->isInteractive()) {
            $default = file_exists($this->config->getStr('service.project_config_dir')) || is_dir('.git') ? '.' : null;
            $sourceDirOption = $this->questionHelper->askInput('Source directory', $default);
        }

        if ($sourceDirOption) {
            $sourceDir = realpath($sourceDirOption);
            if ($sourceDir === false || !is_dir($sourceDir)) {
                throw new InvalidArgumentException('Source directory not found: ' . $sourceDirOption);
            }

            // Sensible handling if the user provides a project root as the
            // source directory.
            if (file_exists($sourceDir . $this->config->getStr('local.project_config'))) {
                $projectRoot = $sourceDir;
            }
        } elseif (!$projectRoot) {
            throw new RootNotFoundException('Project root not found. Specify --source or go to a project directory.');
        } else {
            $sourceDir = $projectRoot;
        }

        $destination = $input->getOption('destination');

        // If no project root is found, ask the user for a destination path.
        if (!$projectRoot && !$destination && $input->isInteractive()) {
            $default = is_dir($sourceDir . '/.git') && $sourceDir === getcwd()
                ? $this->config->getStr('local.web_root')
                : null;
            $destination = $this->questionHelper->askInput('Build destination', $default);
        }

        if ($destination) {
            $fs = $this->filesystem;
            $destination = $fs->makePathAbsolute($destination);
        } elseif (!$projectRoot) {
            throw new RootNotFoundException(
                'Project root not found. Specify --destination or go to a project directory.',
            );
        } else {
            $destination = $projectRoot . '/' . $this->config->getStr('local.web_root');
        }

        // Ensure no conflicts between source and destination.
        if (str_starts_with($sourceDir, $destination)) {
            throw new InvalidArgumentException("The destination '$destination' conflicts with the source '$sourceDir'");
        }

        // Ask the user about overwriting the destination, if a project root was
        // not found.
        if (!$projectRoot && file_exists($destination)) {
            if (!is_writable($destination)) {
                $this->stdErr->writeln("The destination exists and is not writable: <error>$destination</error>");
                return 1;
            }
            $default = is_link($destination);
            if (!$this->questionHelper->confirm(
                "The destination exists: <comment>$destination</comment>. Overwrite?",
                $default,
            )) {
                return 1;
            }
        }

        $apps = $input->getArgument('app');
        $success = $this->localBuild->build($input->getOptions(), $sourceDir, $destination, $apps);

        return $success ? 0 : 1;
    }
}
