<?php
namespace CommerceGuys\Platform\Cli\Local;

use CommerceGuys\Platform\Cli\Helper\FilesystemHelper;
use CommerceGuys\Platform\Cli\Helper\GitHelper;
use CommerceGuys\Platform\Cli\Local\Toolstack\ToolstackInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Parser;

class LocalBuild
{

    protected $settings;
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
        );
    }

    /**
     * @param array  $settings
     * @param object $fsHelper
     * @param object $gitHelper
     */
    public function __construct(array $settings = array(), $fsHelper = null, $gitHelper = null)
    {
        $this->settings = $settings;
        $this->fsHelper = $fsHelper ?: new FilesystemHelper();
        $this->fsHelper->setRelativeLinks(empty($settings['absoluteLinks']));
        $this->gitHelper = $gitHelper ?: new GitHelper();
    }

    /**
     * @param string          $projectRoot
     * @param OutputInterface $output
     *
     * @return bool
     */
    public function buildProject($projectRoot, OutputInterface $output)
    {
        $repositoryRoot = $this->getRepositoryRoot($projectRoot);
        $success = true;
        foreach ($this->getApplications($repositoryRoot) as $appRoot) {
            $success = $this->buildApp($appRoot, $projectRoot, $output) && $success;
        }
        if (empty($this->settings['noClean'])) {
            $output->writeln("Cleaning up...");
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
            ->name('.platform')
            ->depth('> 0');
        if ($finder->count() == 0) {
            return array($repositoryRoot);
        }
        $applications = array();
        /** @var \Symfony\Component\Finder\SplFileInfo $file */
        foreach ($finder as $file) {
            $filename = $file->getRealPath();
            $appRoot = dirname($filename);
            if (basename($appRoot) == '.platform') {
                $appRoot = dirname($appRoot);
            }
            $applications[basename($appRoot)] = $appRoot;
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
        if (!isset($config['name'])) {
            $dir = basename(dirname($appRoot));
            if ($dir != 'repository') {
                $config['name'] = $dir;
            }
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
            if ((!$toolstackChoice && $toolstack->detect($appRoot))
                || $toolstackChoice == $toolstack->getKey()) {
                return $toolstack;
            }
        }
        if ($toolstackChoice) {
            throw new \Exception("Toolstack not found: $toolstackChoice");
        }

        return false;
    }

    /**
     * @var string $projectRoot
     * @return string
     */
    protected function getRepositoryRoot($projectRoot)
    {
        return $projectRoot . '/repository';
    }

    /**
     * @param string $appRoot
     *
     * @return string|false
     */
    protected function getTreeId($appRoot)
    {
        $hashes = array();

        // Get a hash representing all the files in the application, excluding
        // the .platform folder.
        $tree = $this->gitHelper->execute(array('ls-tree', 'HEAD'), $appRoot, true);
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

        // Combine them all.
        return sha1(implode(' ', $hashes));
    }

    /**
     * @param string          $appRoot
     * @param string          $projectRoot
     * @param OutputInterface $output
     *
     * @return bool
     */
    protected function buildApp($appRoot, $projectRoot, OutputInterface $output)
    {
        $appConfig = $this->getAppConfig($appRoot);
        $verbose = $output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE;

        $multiApp = $appRoot != $projectRoot . '/repository';
        $appName = isset($appConfig['name']) ? $appConfig['name'] : false;
        $buildName = date('Y-m-d--H-i-s') . '--' . $this->settings['environmentId'];

        if ($multiApp && $appName) {
            $buildName .= '--' . $appName;
        }

        $buildDir = $projectRoot . '/builds/' . $buildName;

        $toolstack = $this->getToolstack($appRoot, $appConfig);
        if (!$toolstack) {
            $output->writeln("<comment>Could not detect toolstack for directory: $appRoot</comment>");

            return false;
        }

        $toolstack->prepare($buildDir, $appRoot, $projectRoot, $this->settings);

        $archive = false;
        if (empty($this->settings['noArchive'])) {
            $treeId = $this->getTreeId($appRoot);
            if ($treeId) {
                if ($verbose) {
                    $output->writeln("Tree ID: $treeId");
                }
                $archive = $projectRoot . '/.build-archives/' . $treeId . '.tar.gz';
            }
        }

        if ($archive && file_exists($archive)) {
            $message = "Extracting archive";
            if ($appName) {
                $message .= " for application <info>$appName</info>";
            }
            $message .= '...';
            $output->writeln($message);
            $this->fsHelper->extractArchive($archive, $buildDir);
        } else {
            $message = "Building application";
            if ($appName) {
                $message .= " <info>$appName</info>";
            }
            $message .= " using the toolstack <info>" . $toolstack->getKey() . "</info>";
            $output->writeln($message);

            $toolstack->setOutput($output);

            $toolstack->build();

            $this->warnAboutHooks($appConfig, $output);

            if ($archive) {
                $output->writeln("Saving build archive...");
                if (!is_dir(dirname($archive))) {
                    mkdir(dirname($archive));
                }
                $this->fsHelper->archiveDir($buildDir, $archive);
            }
        }

        $toolstack->install();

        // Symlink the build into www or www/appname.
        $wwwLink = "$projectRoot/www";
        if ($multiApp) {
            $appDirName = $appName ?: 'default';
            $wwwLink = "$projectRoot/www/$appDirName";
            if (is_link("$projectRoot/www")) {
                $this->fsHelper->remove("$projectRoot/www");
            }
        }
        $this->fsHelper->symlink($buildDir, $wwwLink);

        $message = "Build complete";
        if ($appName) {
            $message .= " for <info>$appName</info>";
        }
        $output->writeln($message);

        return true;
    }

    /**
     * Warn the user that the CLI will not run build/deploy hooks.
     *
     * @param array           $appConfig
     * @param OutputInterface $output
     *
     * @return bool
     */
    protected function warnAboutHooks(array $appConfig, OutputInterface $output)
    {
        if (empty($appConfig['hooks']['build'])) {
            return false;
        }
        $indent = '        ';
        $output->writeln("<comment>You have defined the following hook(s). The CLI cannot run them locally.</comment>");
        foreach (array('build', 'deploy') as $hookType) {
            if (empty($appConfig['hooks'][$hookType])) {
                continue;
            }
            $output->writeln("    $hookType: |");
            $hooks = (array) $appConfig['hooks'][$hookType];
            $asString = implode("\n", array_map('trim', $hooks));
            $withIndent = $indent . str_replace("\n", "\n$indent", $asString);
            $output->writeln($withIndent);
        }

        return true;
    }

    /**
     * Remove old builds.
     *
     * This preserves the currently active build.
     *
     * @param string          $projectRoot
     * @param int             $ttl
     * @param int             $keepMax
     * @param bool            $includeActive
     * @param OutputInterface $output
     *
     * @return int[]
     *   The numbers of kept and deleted builds.
     */
    public function cleanBuilds($projectRoot, $ttl = 86400, $keepMax = 10, $includeActive = false, OutputInterface $output = null)
    {
        // Find all the potentially active symlinks, which might be www itself
        // or symlinks inside www. This is so we can avoid deleting the active
        // build(s).
        $blacklist = array();
        if (!$includeActive) {
            $blacklist = array_map('basename', $this->getActiveBuilds($projectRoot));
        }

        return $this->cleanDirectory($projectRoot . '/builds', $ttl, $keepMax, $blacklist, $output);
    }

    /**
     * @param string $projectRoot
     *
     * @return array The absolute paths to any active builds in the project.
     */
    protected function getActiveBuilds($projectRoot)
    {
        $www = $projectRoot . '/www';
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
     * @param int    $ttl
     * @param int    $keepMax
     *
     * @return int[]
     *   The numbers of kept and deleted builds.
     */
    public function cleanArchives($projectRoot, $ttl = 604800, $keepMax = 10)
    {
        return $this->cleanDirectory($projectRoot . '/.build-archives', $ttl, $keepMax);
    }

    /**
     * Remove old files from a directory.
     *
     * @param string          $directory
     * @param int             $ttl
     * @param int             $keepMax
     * @param array           $blacklist
     * @param OutputInterface $output
     *
     * @return int[]
     */
    protected function cleanDirectory($directory, $ttl, $keepMax = 0, array $blacklist = array(), OutputInterface $output = null)
    {
        if (!is_dir($directory)) {
            return array(0, 0);
        }
        $output = $output ?: new NullOutput();
        $handle = opendir($directory);
        $now = time();
        $numDeleted = 0;
        $numKept = 0;
        while ($entry = readdir($handle)) {
            if ($entry[0] == '.') {
                continue;
            }
            if (in_array($entry, $blacklist)) {
                $numKept++;
                continue;
            }
            $filename = $directory . '/' . $entry;
            if (($ttl && $now - filemtime($filename) > $ttl) || $numKept >= $keepMax) {
                $output->writeln("Deleting: $entry");
                $this->fsHelper->remove($filename);
                $numDeleted++;
            } else {
                $numKept++;
            }
        }
        closedir($handle);

        return array($numDeleted, $numKept);
    }

}
