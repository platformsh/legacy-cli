<?php
namespace Platformsh\Cli\Local;

use Platformsh\Cli\CliConfig;
use Platformsh\Cli\Helper\FilesystemHelper;
use Platformsh\Cli\Helper\GitHelper;
use Platformsh\Cli\Helper\ShellHelper;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

class LocalBuild
{

    // Some changes may not be backwards-compatible with previous build
    // archives. Increment this number as breaking changes are released.
    const BUILD_VERSION = 3;

    protected $settings;

    /** @var OutputInterface */
    protected $output;

    /** @var FilesystemHelper */
    protected $fsHelper;

    /** @var GitHelper */
    protected $gitHelper;

    /** @var ShellHelper */
    protected $shellHelper;

    /** @var CliConfig */
    protected $config;

    /**
     * @param array                $settings
     * @param CliConfig|null       $config
     * @param OutputInterface|null $output
     */
    public function __construct(array $settings = [], CliConfig $config = null, OutputInterface $output = null)
    {
        $this->config = $config ?: new CliConfig();
        $this->settings = $settings;
        $this->output = $output ?: new NullOutput();
        $this->shellHelper = new ShellHelper($this->output);
        $this->fsHelper = new FilesystemHelper($this->shellHelper);
        $this->fsHelper->setRelativeLinks(empty($settings['absoluteLinks']));
        $this->gitHelper = new GitHelper($this->shellHelper);

        if ($output !== null && !isset($this->settings['verbosity'])) {
            $this->settings['verbosity'] = $output->getVerbosity();
        }
    }

    /**
     * Build a project from any source directory, targeting any destination.
     *
     * @param string $sourceDir   The absolute path to the source directory.
     * @param string $destination Where the web root(s) will be linked (absolute
     *                            path).
     * @param array  $apps        An array of application names to build.
     *
     * @throws \Exception on failure
     *
     * @return bool
     */
    public function build($sourceDir, $destination = null, array $apps = [])
    {
        $success = true;
        $ids = [];
        foreach (LocalApplication::getApplications($sourceDir, $this->config) as $app) {
            $id = $app->getId();
            $ids[] = $id;
            if ($apps && !in_array($id, $apps)) {
                continue;
            }
            $success = $this->buildApp($app, $sourceDir, $destination) && $success;
        }
        $notFounds = array_diff($apps, $ids);
        if ($notFounds) {
            foreach ($notFounds as $notFound) {
                $this->output->writeln("Application not found: <comment>$notFound</comment>");
            }
        }
        if (empty($this->settings['noClean'])) {
            $this->output->writeln("Cleaning up...");
            $this->cleanBuilds($sourceDir);
            $this->cleanArchives($sourceDir);
        }

        return $success;
    }

    /**
     * Get a hash of the application files.
     *
     * This should change if any of the application files or build settings
     * change.
     *
     * @param string $appRoot
     *
     * @return string|false
     */
    public function getTreeId($appRoot)
    {
        $hashes = [];

        // Get a hash representing all the files in the application, excluding
        // the project config folder (CLI_PROJECT_CONFIG_DIR).
        $tree = $this->gitHelper->execute(['ls-files', '-s'], $appRoot);
        if ($tree === false) {
            return false;
        }
        $tree = preg_replace('#^|\n[^\n]+?' . preg_quote($this->config->get('service.project_config_dir')) . '\n|$#', "\n", $tree);
        $hashes[] = sha1($tree);

        // Include the hashes of untracked and modified files.
        $others = $this->gitHelper->execute(
            ['ls-files', '--modified', '--others', '--exclude-standard', '-x ' . $this->config->get('service.project_config_dir'), '.'],
            $appRoot
        );
        if ($others === false) {
            return false;
        }
        $count = 0;
        foreach (explode("\n", $others) as $filename) {
            if ($count > 5000) {
                return false;
            }
            $filename = "$appRoot/$filename";
            if (is_file($filename)) {
                $hashes[] = sha1_file($filename);
                $count++;
            }
        }

        // Include relevant build settings.
        $irrelevant = ['environmentId', 'appName', 'multiApp', 'noClean', 'verbosity', 'drushConcurrency', 'projectRoot'];
        $settings = array_filter(array_diff_key($this->settings, array_flip($irrelevant)));
        $hashes[] = serialize($settings);

        $hashes[] = self::BUILD_VERSION;

        // Combine them all.
        return sha1(implode(' ', $hashes));
    }

    /**
     * @param LocalApplication $app
     * @param string           $sourceDir
     * @param string           $destination
     *
     * @return bool
     */
    protected function buildApp($app, $sourceDir, $destination = null)
    {
        $verbose = $this->output->isVerbose();

        $destination = $destination ?: $sourceDir . '/' . $this->config->get('local.web_root');
        $appRoot = $app->getRoot();
        $appConfig = $app->getConfig();
        $multiApp = $appRoot != $sourceDir;
        $appId = $app->getId();

        $toolstack = $app->getToolstack();
        if (!$toolstack) {
            $this->output->writeln("Toolstack not found for application <error>$appId</error>");

            return false;
        }

        // Find the right build directory.
        $buildName = $multiApp ? str_replace('/', '-', $appId) : 'default';

        $tmpBuildDir = $sourceDir . '/' . $this->config->get('local.build_dir') . '/' . $buildName . '-tmp';

        if (file_exists($sourceDir . '/.git')) {
            $localProject = new LocalProject();
            $localProject->writeGitExclude($sourceDir);
        }

        if (file_exists($tmpBuildDir)) {
            if (!$this->fsHelper->remove($tmpBuildDir)) {
                $this->output->writeln(sprintf('Failed to remove directory <error>%s</error>', $tmpBuildDir));

                return false;
            }
        }

        // If the destination is inside the source directory, ensure it isn't
        // copied or symlinked into the build.
        if (strpos($destination, $sourceDir) === 0) {
            $toolstack->addIgnoredFiles([
                ltrim(substr($destination, strlen($sourceDir)), '/'),
            ]);
        }

        // Warn about a mismatched PHP version.
        if (isset($appConfig['type']) && strpos($appConfig['type'], ':')) {
            list($stack, $version) = explode(':', $appConfig['type'], 2);
            if ($stack === 'php' && version_compare($version, PHP_VERSION, '>')) {
                $this->output->writeln(sprintf(
                    '<comment>Warning:</comment> the application <comment>%s</comment> expects PHP %s, but the system version is %s.',
                    $appId,
                    $version,
                    PHP_VERSION
                ));
            }
        }

        $toolstack->setOutput($this->output);

        $buildSettings = $this->settings + [
            'multiApp' => $multiApp,
            'sourceDir' => $sourceDir,
        ];
        $toolstack->prepare($tmpBuildDir, $app, $this->config, $buildSettings);

        $archive = false;
        if (empty($this->settings['noArchive']) && empty($this->settings['noCache'])) {
            $treeId = $this->getTreeId($appRoot);
            if ($treeId) {
                if ($verbose) {
                    $this->output->writeln("Tree ID: $treeId");
                }
                $archive = $sourceDir . '/' . $this->config->get('local.archive_dir') . '/' . $treeId . '.tar.gz';
            }
        }

        if ($archive && file_exists($archive)) {
            $message = "Extracting archive for application <info>$appId</info>";
            $this->output->writeln($message);
            $this->fsHelper->extractArchive($archive, $tmpBuildDir);
        } else {
            $message = "Building application <info>$appId</info>";
            if (isset($appConfig['type'])) {
                $message .= ' (runtime type: ' . $appConfig['type'] . ')';
            }
            $this->output->writeln($message);

            $toolstack->build();

            if ($this->runPostBuildHooks($appConfig, $toolstack->getAppDir()) === false) {
                // The user may not care if build hooks fail, but we should
                // not archive the result.
                $archive = false;
            }

            if ($archive && $toolstack->canArchive()) {
                $this->output->writeln("Saving build archive");
                if (!is_dir(dirname($archive))) {
                    mkdir(dirname($archive));
                }
                $this->fsHelper->archiveDir($tmpBuildDir, $archive);
            }
        }

        // The build is complete. Move the directory.
        $buildDir = substr($tmpBuildDir, 0, strlen($tmpBuildDir) - 4);
        if (file_exists($buildDir)) {
            $previousBuildArchive = dirname($buildDir) . '/' . basename($buildDir) . '-old.tar.gz';
            $this->output->writeln("Backing up previous build to: " . $previousBuildArchive);
            $this->fsHelper->archiveDir($buildDir, $previousBuildArchive);
            if (!$this->fsHelper->remove($buildDir)) {
                $this->output->writeln(sprintf('Failed to remove directory <error>%s</error>', $buildDir));

                return false;
            }
        }
        if (!rename($tmpBuildDir, $buildDir)) {
            $this->output->writeln(sprintf('Failed to move temporary build directory into <error>%s</error>', $buildDir));

            return false;
        }

        $toolstack->setBuildDir($buildDir);
        $toolstack->install();

        $webRoot = $toolstack->getWebRoot();

        // Symlink the built web root ($webRoot) into www or www/appId.
        if (!is_dir($webRoot)) {
            $this->output->writeln("\nWeb root not found: <error>$webRoot</error>\n");

            return false;
        }
        if ($multiApp) {
            $appDir = str_replace('/', '-', $appId);
            if (is_link($destination)) {
                $this->fsHelper->remove($destination);
            }
            $destination .= "/$appDir";
        }

        $this->fsHelper->symlink($webRoot, $destination);

        $message = "\nBuild complete for application <info>$appId</info>";
        $this->output->writeln($message);
        $this->output->writeln("Web root: <info>$destination</info>\n");

        return true;
    }

    /**
     * Run post-build hooks.
     *
     * @param array  $appConfig
     * @param string $buildDir
     *
     * @return bool|null
     *   False if the build hooks fail, true if they succeed, null if not
     *   applicable.
     */
    protected function runPostBuildHooks(array $appConfig, $buildDir)
    {
        if (!isset($appConfig['hooks']['build'])) {
            return null;
        }
        if (!empty($this->settings['noBuildHooks'])) {
            $this->output->writeln("Skipping post-build hooks");
            return null;
        }
        $this->output->writeln("Running post-build hooks");
        $command = implode(';', (array) $appConfig['hooks']['build']);
        $code = $this->shellHelper->executeSimple($command, $buildDir);
        if ($code !== true) {
            $this->output->writeln("<comment>The build hook failed with the exit code: $code</comment>");
            return false;
        }

        return true;
    }

    /**
     * Remove old builds.
     *
     * This preserves the currently active build.
     *
     * @param string $projectRoot
     * @param int    $maxAge
     * @param int    $keepMax
     * @param bool   $includeActive
     * @param bool   $quiet
     *
     * @deprecated No longer needed from 3.0.0.
     *
     * @return int[]
     *   The numbers of deleted and kept builds.
     */
    public function cleanBuilds($projectRoot, $maxAge = null, $keepMax = 10, $includeActive = false, $quiet = true)
    {
        // Find all the potentially active symlinks, which might be www itself
        // or symlinks inside www. This is so we can avoid deleting the active
        // build(s).
        $blacklist = [];
        if (!$includeActive) {
            $blacklist = $this->getActiveBuilds($projectRoot);
        }

        return $this->cleanDirectory(
            $projectRoot . '/' . $this->config->get('local.build_dir'),
            $maxAge,
            $keepMax,
            $blacklist,
            $quiet
        );
    }

    /**
     * @param string $projectRoot
     *
     * @throws \Exception If it cannot be determined whether or not a symlink
     *                    points to a genuine active build.
     *
     * @return array The absolute paths to any active builds in the project.
     */
    protected function getActiveBuilds($projectRoot)
    {
        $www = $projectRoot . '/' . $this->config->get('local.web_root');
        if (!file_exists($www)) {
            return [];
        }
        $links = [$www];
        if (!is_link($www) && is_dir($www)) {
            $finder = new Finder();
            /** @var \Symfony\Component\Finder\SplFileInfo $file */
            foreach ($finder->in($www)
                            ->directories()
                            ->depth(0) as $file) {
                $links[] = $file->getPathname();
            }
        }
        $activeBuilds = [];
        $buildsDir = $projectRoot . '/' . $this->config->get('local.build_dir');
        foreach ($links as $link) {
            if (is_link($link) && ($target = readlink($link))) {
                // Make the target into an absolute path.
                $target = $target[0] === DIRECTORY_SEPARATOR ? $target : realpath(dirname($link) . '/' . $target);
                if (!$target) {
                    continue;
                }
                // Ignore the target if it doesn't point to a build in 'builds'.
                if (strpos($target, $buildsDir) === false) {
                    continue;
                }
                // The target should just be one level below the 'builds'
                // directory, not more.
                while (dirname($target) != $buildsDir) {
                    $target = dirname($target);
                    if (strpos($target, $buildsDir) === false) {
                        throw new \Exception('Error resolving active build directory');
                    }
                }
                $activeBuilds[] = $target;
            }
        }

        return $activeBuilds;
    }

    /**
     * Remove old build archives.
     *
     * @param string $projectRoot
     * @param int    $maxAge
     * @param int    $keepMax
     * @param bool   $quiet
     *
     * @return int[]
     *   The numbers of deleted and kept builds.
     */
    public function cleanArchives($projectRoot, $maxAge = null, $keepMax = 10, $quiet = true)
    {
        return $this->cleanDirectory(
            $projectRoot . '/' . $this->config->get('local.archive_dir'),
            $maxAge,
            $keepMax,
            [],
            $quiet
        );
    }

    /**
     * Remove old files from a directory.
     *
     * @param string $directory
     * @param int    $maxAge
     * @param int    $keepMax
     * @param array  $blacklist
     * @param bool   $quiet
     *
     * @return int[]
     */
    protected function cleanDirectory($directory, $maxAge = null, $keepMax = 5, array $blacklist = [], $quiet = true)
    {
        if (!is_dir($directory)) {
            return [0, 0];
        }
        $files = glob($directory . '/*');
        if (!$files) {
            return [0, 0];
        }
        // Sort files by modified time (descending).
        usort(
            $files,
            function ($a, $b) {
                return filemtime($a) < filemtime($b);
            }
        );
        $now = time();
        $numDeleted = 0;
        $numKept = 0;
        foreach ($files as $filename) {
            if (in_array($filename, $blacklist)) {
                $numKept++;
                continue;
            }
            if ($keepMax !== null && ($numKept >= $keepMax) || ($maxAge !== null && $now - filemtime($filename) > $maxAge)) {
                if (!$quiet) {
                    $this->output->writeln("Deleting: " . basename($filename));
                }
                if ($this->fsHelper->remove($filename)) {
                    $numDeleted++;
                }
                elseif (!$quiet) {
                    $this->output->writeln("Failed to delete: <error>" . basename($filename) . "</error>");
                }
            } else {
                $numKept++;
            }
        }

        return [$numDeleted, $numKept];
    }

}
