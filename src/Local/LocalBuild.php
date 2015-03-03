<?php
namespace Platformsh\Cli\Local;

use Platformsh\Cli\Helper\FilesystemHelper;
use Platformsh\Cli\Helper\GitHelper;
use Platformsh\Cli\Helper\ShellHelper;
use Platformsh\Cli\Local\Toolstack\ToolstackInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Parser;

class LocalBuild
{

    protected $settings;
    protected $output;
    protected $fsHelper;
    protected $gitHelper;

    /**
     * @return ToolstackInterface[]
     */
    public function getToolstacks()
    {
        return array(
          new Toolstack\Drupal(),
          new Toolstack\Symfony(),
          new Toolstack\Composer(),
        );
    }

    /**
     * @param array           $settings
     * @param OutputInterface $output
     * @param object          $fsHelper
     * @param object          $gitHelper
     */
    public function __construct(array $settings = array(), OutputInterface $output = null, $fsHelper = null, $gitHelper = null)
    {
        $this->settings = $settings;
        $this->output = $output ?: new NullOutput();
        $this->fsHelper = $fsHelper ?: new FilesystemHelper(new ShellHelper($output));
        $this->fsHelper->setRelativeLinks(empty($settings['absoluteLinks']));
        $this->gitHelper = $gitHelper ?: new GitHelper();
    }

    /**
     * @param string $projectRoot The absolute path to the project root.
     * @param array  $apps        An array of application names to build.
     *
     * @throws \Exception on failure
     *
     * @return bool
     */
    public function buildProject($projectRoot, array $apps = array())
    {
        $repositoryRoot = $projectRoot . '/' . LocalProject::REPOSITORY_DIR;
        $success = true;
        $identifiers = array();
        foreach ($this->getApplications($repositoryRoot) as $identifier => $appRoot) {
            $appConfig = $this->getAppConfig($appRoot);
            $appIdentifier = isset($appConfig['name']) ? $appConfig['name'] : $identifier;
            $appConfig['_identifier'] = $appIdentifier;
            $identifiers[] = $appIdentifier;
            if ($apps && !in_array($appIdentifier, $apps)) {
                continue;
            }
            $success = $this->buildApp($appRoot, $projectRoot, $appConfig) && $success;
        }
        $notFounds = array_diff($apps, $identifiers);
        if ($notFounds) {
            foreach ($notFounds as $notFound) {
                $this->output->writeln("Application not found: <comment>$notFound</comment>");
            }
        }
        if (empty($this->settings['noClean'])) {
            if ($this->output->isVerbose()) {
                $this->output->writeln("Cleaning up...");
            }
            $this->cleanBuilds($projectRoot);
            $this->cleanArchives($projectRoot);
        }

        return $success;
    }

    /**
     * Get a list of applications in the repository.
     *
     * @param string $repositoryRoot The absolute path to the repository.
     *
     * @return string[]    A list of directories containing applications.
     */
    public function getApplications($repositoryRoot)
    {
        $finder = new Finder();
        $finder->in($repositoryRoot)
               ->ignoreDotFiles(false)
               ->name('.platform.app.yaml')
               ->depth('> 0')
               ->depth('< 5');
        if ($finder->count() == 0) {
            return array('default' => $repositoryRoot);
        }
        $applications = array();
        /** @var \Symfony\Component\Finder\SplFileInfo $file */
        foreach ($finder as $file) {
            $filename = $file->getRealPath();
            $appRoot = dirname($filename);
            $identifier = ltrim(str_replace($repositoryRoot, '', $appRoot), '/');
            $applications[$identifier] = $appRoot;
        }

        return array_unique($applications);
    }

    /**
     * Get the application's configuration, parsed from its YAML definition.
     *
     * @param string $appRoot The absolute path to the application.
     *
     * @return array
     */
    public function getAppConfig($appRoot)
    {
        $config = array();
        if (file_exists($appRoot . '/.platform.app.yaml')) {
            $parser = new Parser();
            $config = (array) $parser->parse(file_get_contents($appRoot . '/.platform.app.yaml'));
        }

        return $config;
    }

    /**
     * Get the toolstack for a particular application.
     *
     * @param string $appRoot   The absolute path to the application.
     * @param mixed  $appConfig The application's configuration.
     *
     * @throws \Exception   If a specified toolstack is not found.
     *
     * @return ToolstackInterface|false
     */
    public function getToolstack($appRoot, array $appConfig = array())
    {
        $toolstackChoice = false;
        if (isset($appConfig['toolstack'])) {
            $toolstackChoice = $appConfig['toolstack'];
        }
        foreach (self::getToolstacks() as $toolstack) {
            $key = $toolstack->getKey();
            if ((!$toolstackChoice && $toolstack->detect($appRoot))
              || ($key && $toolstackChoice === $key)
            ) {
                return $toolstack;
            }
        }
        if ($toolstackChoice) {
            throw new \Exception("Toolstack not found: $toolstackChoice");
        }

        return false;
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
    protected function getTreeId($appRoot)
    {
        $hashes = array();

        // Get a hash representing all the files in the application, excluding
        // the .platform folder.
        $tree = $this->gitHelper->execute(array('ls-tree', 'HEAD'), $appRoot);
        if ($tree === false) {
            return false;
        }
        $tree = preg_replace('#^|\n[^\n]+?\.platform\n|$#', "\n", $tree);
        $hashes[] = sha1($tree);

        // Include the hashes of untracked and modified files.
        $others = $this->gitHelper->execute(
          array('ls-files', '--modified', '--others', '--exclude-standard', '-x .platform', '.'),
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
        $irrelevant = array('environmentId', 'appName', 'multiApp', 'noClean', 'verbosity', 'drushConcurrency');
        $settings = array_filter(array_diff_key($this->settings, array_flip($irrelevant)));
        $hashes[] = serialize($settings);

        // Combine them all.
        return sha1(implode(' ', $hashes));
    }

    /**
     * @param string $appRoot
     * @param string $projectRoot
     * @param array  $appConfig
     *
     * @return bool
     */
    protected function buildApp($appRoot, $projectRoot, array $appConfig = array())
    {
        $verbose = $this->output->isVerbose();

        $multiApp = $appRoot != $projectRoot . '/' . LocalProject::REPOSITORY_DIR;
        $appName = isset($appConfig['name']) ? $appConfig['name'] : false;
        $appIdentifier = $appName ?: $appConfig['_identifier'];

        $buildName = date('Y-m-d--H-i-s') . '--' . $this->settings['environmentId'];
        if ($multiApp) {
            $buildName .= '--' . str_replace('/', '-', $appIdentifier);
        }
        $buildDir = $projectRoot . '/' . LocalProject::BUILD_DIR . '/' . $buildName;

        $toolstack = $this->getToolstack($appRoot, $appConfig);

        if ($toolstack) {
            $buildSettings = $this->settings + array(
                'multiApp' => $multiApp,
                'appName' => $appName,
              );
            $toolstack->prepare($buildDir, $appRoot, $projectRoot, $buildSettings);

            $archive = false;
            if (empty($this->settings['noArchive'])) {
                $treeId = $this->getTreeId($appRoot);
                if ($treeId) {
                    if ($verbose) {
                        $this->output->writeln("Tree ID: $treeId");
                    }
                    $archive = $projectRoot . '/' . LocalProject::ARCHIVE_DIR . '/' . $treeId . '.tar.gz';
                }
            }

            if ($archive && file_exists($archive)) {
                $message = "Extracting archive for application <info>$appIdentifier</info>";
                $message .= '...';
                $this->output->writeln($message);
                $this->fsHelper->extractArchive($archive, $buildDir);
            } else {
                $message = "Building application <info>$appIdentifier</info>";
                if ($key = $toolstack->getKey()) {
                    $message .= " using the toolstack <info>$key</info>";
                }
                $this->output->writeln($message);

                $toolstack->setOutput($this->output);

                $toolstack->build();

                $this->warnAboutHooks($appConfig);

                if ($archive && empty($toolstack->preventArchive)) {
                    $this->output->writeln("Saving build archive...");
                    if (!is_dir(dirname($archive))) {
                        mkdir(dirname($archive));
                    }
                    $this->fsHelper->archiveDir($buildDir, $archive);
                }
            }

            $toolstack->install();

            // Allow the toolstack to change the build dir.
            $buildDir = $toolstack->getBuildDir();
        } else {
            $buildDir = $appRoot;
            $this->warnAboutHooks($appConfig);
        }

        // Symlink the build into www or www/appIdentifier.
        $wwwLink = $projectRoot . '/' . LocalProject::WEB_ROOT;
        if ($multiApp) {
            $appDir = str_replace('/', '-', $appIdentifier);
            if (is_link($wwwLink)) {
                $this->fsHelper->remove($wwwLink);
            }
            $wwwLink .= "/$appDir";
        }
        $symlinkTarget = $this->fsHelper->symlink($buildDir, $wwwLink);

        if ($verbose) {
            $this->output->writeln("Created symlink: $wwwLink -> $symlinkTarget");
        }

        $message = "Build complete for <info>$appIdentifier</info>";
        $this->output->writeln($message);

        return true;
    }

    /**
     * Warn the user that the CLI will not run build/deploy hooks.
     *
     * @param array $appConfig
     *
     * @return bool
     */
    protected function warnAboutHooks(array $appConfig)
    {
        if (empty($appConfig['hooks']['build'])) {
            return false;
        }
        $indent = '        ';
        $this->output->writeln(
          "<comment>You have defined the following hook(s). The CLI cannot run them locally.</comment>"
        );
        foreach (array('build', 'deploy') as $hookType) {
            if (empty($appConfig['hooks'][$hookType])) {
                continue;
            }
            $this->output->writeln("    $hookType: |");
            $hooks = (array) $appConfig['hooks'][$hookType];
            $asString = implode("\n", array_map('trim', $hooks));
            $withIndent = $indent . str_replace("\n", "\n$indent", $asString);
            $this->output->writeln($withIndent);
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
     * @return int[]
     *   The numbers of deleted and kept builds.
     */
    public function cleanBuilds($projectRoot, $maxAge = 86400, $keepMax = 10, $includeActive = false, $quiet = true)
    {
        // Find all the potentially active symlinks, which might be www itself
        // or symlinks inside www. This is so we can avoid deleting the active
        // build(s).
        $blacklist = array();
        if (!$includeActive) {
            $blacklist = $this->getActiveBuilds($projectRoot);
        }

        return $this->cleanDirectory(
          $projectRoot . '/' . LocalProject::BUILD_DIR,
          $maxAge,
          $keepMax,
          $blacklist,
          $quiet
        );
    }

    /**
     * @param string $projectRoot
     *
     * @return array The absolute paths to any active builds in the project.
     */
    protected function getActiveBuilds($projectRoot)
    {
        $www = $projectRoot . '/' . LocalProject::WEB_ROOT;
        if (!file_exists($www)) {
            return array();
        }
        $links = array($www);
        if (is_dir($www)) {
            $finder = new Finder();
            /** @var \Symfony\Component\Finder\SplFileInfo $file */
            foreach ($finder->in($www)
                            ->directories()
                            ->depth(0) as $file) {
                $links[] = $file->getPathname();
            }
        }
        $activeBuilds = array();
        foreach ($links as $link) {
            if (is_link($link) && ($target = readlink($link)) && file_exists($target)) {
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
    public function cleanArchives($projectRoot, $maxAge = 604800, $keepMax = 10, $quiet = true)
    {
        return $this->cleanDirectory(
          $projectRoot . '/' . LocalProject::ARCHIVE_DIR,
          $maxAge,
          $keepMax,
          array(),
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
    protected function cleanDirectory($directory, $maxAge, $keepMax = 0, array $blacklist = array(), $quiet = false)
    {
        if (!is_dir($directory)) {
            return array(0, 0);
        }
        $files = glob($directory . '/*');
        if (!$files) {
            return array(0, 0);
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
            if ($numKept >= $keepMax || ($maxAge && $now - filemtime($filename) > $maxAge)) {
                if (!$quiet) {
                    $this->output->writeln("Deleting: " . basename($filename));
                }
                $this->fsHelper->remove($filename);
                $numDeleted++;
            } else {
                $numKept++;
            }
        }

        return array($numDeleted, $numKept);
    }

}
