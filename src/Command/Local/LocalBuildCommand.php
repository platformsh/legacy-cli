<?php
namespace Platformsh\Cli\Command\Local;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\LocalBuild;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LocalBuildCommand extends CommandBase
{
    protected $local = true;

    protected function configure()
    {
        $this
            ->setName('local:build')
            ->setAliases(['build'])
            ->addArgument('app', InputArgument::IS_ARRAY, 'Specify application(s) to build')
            ->setDescription('Build the current project locally')
            ->addOption(
                'abslinks',
                'a',
                InputOption::VALUE_NONE,
                'Use absolute links'
            )
            ->addOption(
                'source',
                's',
                InputOption::VALUE_REQUIRED,
                'The source directory. Defaults to the current project root.'
            )
            ->addOption(
                'destination',
                'd',
                InputOption::VALUE_REQUIRED,
                'The destination, to which the web root of each app will be symlinked. Default: ' . self::$config->get('local.web_root')
            )
            ->addOption(
                'copy',
                'c',
                InputOption::VALUE_NONE,
                'Copy to a build directory, instead of symlinking from the source'
            )
            ->addOption(
                'no-clean',
                null,
                InputOption::VALUE_NONE,
                'Do not remove old builds'
            )
            ->addOption(
                'no-archive',
                null,
                InputOption::VALUE_NONE,
                'Do not create or use a build archive'
            )
            ->addOption(
                'no-backup',
                null,
                InputOption::VALUE_NONE,
                'Do not back up the previous build'
            )
            ->addOption(
                'no-cache',
                null,
                InputOption::VALUE_NONE,
                'Disable caching'
            )
            ->addOption(
                'no-build-hooks',
                null,
                InputOption::VALUE_NONE,
                'Do not run post-build hooks'
            )
            ->addOption(
                'working-copy',
                null,
                InputOption::VALUE_NONE,
                'Drush: use git to clone a repository of each Drupal module rather than simply downloading a version'
            )
            ->addOption(
                'concurrency',
                null,
                InputOption::VALUE_REQUIRED,
                'Drush: set the number of concurrent projects that will be processed at the same time',
                4
            )
            ->addOption(
                'lock',
                null,
                InputOption::VALUE_NONE,
                'Drush: create or update a lock file (only available with Drush version 7+)'
            );
        $this->addExample('Build the current project');
        $this->addExample('Build the app "example" without symlinking the source files', 'example --copy');
        $this->addExample('Rebuild the current project without using an archive', '--no-archive');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectRoot = $this->getProjectRoot();

        /** @var \Platformsh\Cli\Helper\QuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');

        $sourceDirOption = $input->getOption('source');

        // If no project root is found, ask the user for a source directory.
        if (!$projectRoot && !$sourceDirOption && $input->isInteractive()) {
            $default = file_exists(self::$config->get('service.app_config_file')) || is_dir('.git') ? '.' : null;
            $sourceDirOption = $questionHelper->askInput('Source directory', $default);
        }

        if ($sourceDirOption) {
            $sourceDir = realpath($sourceDirOption);
            if (!is_dir($sourceDir)) {
                throw new \InvalidArgumentException('Source directory not found: ' . $sourceDirOption);
            }
            // Sensible handling if the user provides a project root as the
            // source directory.
            elseif (file_exists($sourceDir . self::$config->get('local.project_config'))) {
                $projectRoot = $sourceDir;
                $sourceDir = $projectRoot;
            }
        }
        elseif (!$projectRoot) {
            throw new RootNotFoundException('Project root not found. Specify --source or go to a project directory.');
        }
        else {
            $sourceDir = $projectRoot;
        }

        $destination = $input->getOption('destination');

        // If no project root is found, ask the user for a destination path.
        if (!$projectRoot && !$destination && $input->isInteractive()) {
            $default = is_dir($sourceDir . '/.git') && $sourceDir === getcwd() ? self::$config->get('local.web_root') : null;
            $destination = $questionHelper->askInput('Build destination', $default);
        }

        if ($destination) {
            /** @var \Platformsh\Cli\Helper\FilesystemHelper $fsHelper */
            $fsHelper = $this->getHelper('fs');
            $destination = $fsHelper->makePathAbsolute($destination);
        }
        elseif (!$projectRoot) {
            throw new RootNotFoundException('Project root not found. Specify --destination or go to a project directory.');
        }
        else {
            $destination = $projectRoot . '/' . self::$config->get('local.web_root');
        }

        // Ensure no conflicts between source and destination.
        if (strpos($sourceDir, $destination) === 0) {
            throw new \InvalidArgumentException("The destination '$destination' conflicts with the source '$sourceDir'");
        }

        // Ask the user about overwriting the destination, if a project root was
        // not found.
        if (!$projectRoot && file_exists($destination)) {
            if (!is_writable($destination)) {
                $this->stdErr->writeln("The destination exists and is not writable: <error>$destination</error>");
                return 1;
            }
            $default = is_link($destination);
            if (!$questionHelper->confirm("The destination exists: <comment>$destination</comment>. Overwrite?", $default)) {
                return 1;
            }
        }

        // Map input options to build settings.
        $settingsMap = [
            'absoluteLinks' => 'abslinks',
            'copy' => 'copy',
            'drushConcurrency' => 'concurrency',
            'drushWorkingCopy' => 'working-copy',
            'drushUpdateLock' => 'lock',
            'noArchive' => 'no-archive',
            'noBackup' => 'no-backup',
            'noCache' => 'no-cache',
            'noClean' => 'no-clean',
            'noBuildHooks' => 'no-build-hooks',
        ];
        $settings = [];
        foreach ($settingsMap as $setting => $option) {
            if ($input->hasOption($option)) {
                $settings[$setting] = $input->getOption($option);
            }
        }

        $apps = $input->getArgument('app');

        $builder = new LocalBuild($settings, self::$config, $this->stdErr);
        $success = $builder->build($sourceDir, $destination, $apps);

        return $success ? 0 : 1;
    }
}
