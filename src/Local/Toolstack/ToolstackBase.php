<?php

namespace Platformsh\Cli\Local\Toolstack;

use Platformsh\Cli\Helper\FilesystemHelper;
use Platformsh\Cli\Helper\GitHelper;
use Platformsh\Cli\Helper\ShellHelper;
use Platformsh\Cli\Helper\ShellHelperInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class ToolstackBase implements ToolstackInterface
{

    /**
     * Files from the app root to ignore during install.
     *
     * @var string[]
     */
    protected $ignoredFiles = [];

    /**
     * Special destinations for installation.
     *
     * @var array
     *   An array of filenames in the app root, mapped to destinations. The
     *   destinations are filenames supporting the replacements:
     *     "{webroot}" - see getWebRoot() (usually /app/public on Platform.sh)
     *     "{approot}" - the $buildDir (usually /app on Platform.sh)
     */
    protected $specialDestinations = [];

    protected $settings = [];
    protected $appRoot;
    protected $documentRoot;
    protected $buildDir;
    protected $copy = false;

    /** @var OutputInterface */
    protected $output;

    /** @var FilesystemHelper */
    protected $fsHelper;

    /** @var GitHelper */
    protected $gitHelper;

    /** @var ShellHelperInterface */
    protected $shellHelper;

    /**
     * Whether all app files have just been symlinked or copied to the build.
     *
     * @var bool
     */
    private $buildInPlace = false;

    /**
     * @param object               $fsHelper
     * @param ShellHelperInterface $shellHelper
     * @param object               $gitHelper
     */
    public function __construct($fsHelper = null, ShellHelperInterface $shellHelper = null, $gitHelper = null)
    {
        $this->shellHelper = $shellHelper ?: new ShellHelper();
        $this->fsHelper = $fsHelper ?: new FilesystemHelper($this->shellHelper);
        $this->gitHelper = $gitHelper ?: new GitHelper($this->shellHelper);

        $this->specialDestinations = [
            "favicon.ico" => "{webroot}",
            "robots.txt" => "{webroot}",
        ];

        // Platform.sh has '.platform.app.yaml', but we need to be stricter.
        $this->ignoredFiles = ['.*'];
    }

    /**
     * @inheritdoc
     */
    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
        $this->shellHelper->setOutput($output);
    }

    /**
     * @inheritdoc
     */
    public function addIgnoredFiles(array $ignoredFiles)
    {
        $this->ignoredFiles = array_merge($this->ignoredFiles, $ignoredFiles);
    }

    /**
     * @inheritdoc
     */
    public function prepare($buildDir, $documentRoot, $appRoot, array $settings)
    {
        $this->appRoot = $appRoot;
        $this->settings = $settings;

        $this->buildDir = $buildDir;
        $this->documentRoot = ltrim($documentRoot, '/');

        $this->copy = !empty($settings['copy']);
        $this->fsHelper->setRelativeLinks(empty($settings['absoluteLinks']));
    }

    /**
     * Process the defined special destinations.
     */
    protected function processSpecialDestinations()
    {
        foreach ($this->specialDestinations as $sourcePattern => $relDestination) {
            $matched = glob($this->appRoot . '/' . $sourcePattern, GLOB_NOSORT);
            if (!$matched) {
                continue;
            }
            if ($relDestination === '{webroot}' && $this->buildInPlace) {
                continue;
            }

            // On Platform these replacements would be a bit different.
            $absDestination = str_replace(
                ['{webroot}', '{approot}'],
                [$this->getWebRoot(), $this->buildDir],
                $relDestination
            );

            foreach ($matched as $source) {
                // Ignore the source if it's in ignoredFiles.
                $relSource = str_replace($this->appRoot . '/', '', $source);
                if (in_array($relSource, $this->ignoredFiles)) {
                    continue;
                }
                $destination = $absDestination;
                // Do not overwrite directories with files.
                if (!is_dir($source) && is_dir($destination)) {
                    $destination = $destination . '/' . basename($source);
                }
                // Ignore if source and destination are the same.
                if ($destination === $source) {
                    continue;
                }
                if ($this->copy) {
                    $this->output->writeln("Copying $relSource to $relDestination");
                }
                else {
                    $this->output->writeln("Symlinking $relSource to $relDestination");
                }
                // Delete existing files, emitting a warning.
                if (file_exists($destination)) {
                    $this->output->writeln(
                        sprintf(
                            "Overriding existing path '%s' in destination",
                            str_replace($this->buildDir . '/', '', $destination)
                        )
                    );
                    $this->fsHelper->remove($destination);
                }
                if ($this->copy) {
                    $this->fsHelper->copy($source, $destination);
                }
                else {
                    $this->fsHelper->symlink($source, $destination);
                }
            }
        }
    }

    /**
     * Get the directory containing files shared between builds.
     *
     * This will be 'shared' for a single-application project, or
     * 'shared/<appName>' when there are multiple applications.
     *
     * @return string|false
     */
    protected function getSharedDir()
    {
        if (empty($this->settings['sourceDir'])) {
            return false;
        }
        $shared = $this->settings['sourceDir'] . '/' . CLI_LOCAL_SHARED_DIR;
        if (!empty($this->settings['multiApp']) && !empty($this->settings['appName'])) {
            $shared .= '/' . preg_replace('/[^a-z0-9\-_]+/i', '-', $this->settings['appName']);
        }
        if (!is_dir($shared)) {
            mkdir($shared, 0755, true);
        }

        return $shared;
    }

    /**
     * @inheritdoc
     */
    public function getWebRoot()
    {
        return $this->buildDir . '/' . $this->documentRoot;
    }

    /**
     * @return string
     */
    public function getAppDir()
    {
        return $this->buildDir;
    }

    /**
     * Copy, or symlink, files from the app root to the build directory.
     *
     * @return string
     *   The absolute path to the build directory where files have been copied.
     */
    protected function copyToBuildDir()
    {
        $this->buildInPlace = true;
        $buildDir = $this->buildDir;
        if ($this->documentRoot === 'public' && !file_exists($this->appRoot . '/' . $this->documentRoot)) {
            $buildDir .= '/' . $this->documentRoot;
        }
        if ($this->copy) {
            $this->fsHelper->copyAll($this->appRoot, $buildDir, $this->ignoredFiles, true);
        }
        else {
            $this->fsHelper->symLink($this->appRoot, $buildDir);
        }

        return $buildDir;
    }

    /**
     * @inheritdoc
     */
    public function install()
    {
        // Override to define install steps.
    }

    /**
     * @inheritdoc
     */
    public function getKey()
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function canArchive()
    {
        return !$this->buildInPlace || $this->copy;
    }

    /**
     * Create a default .gitignore file for the app.
     *
     * @param string $source The path to a default .gitignore file, relative to
     *                       the 'resources' directory.
     */
    protected function copyGitIgnore($source)
    {
        $source = CLI_ROOT . '/resources/' . $source;
        if (!file_exists($source) || empty($this->settings['sourceDir']) || !!$this->gitHelper->isRepository($this->settings['sourceDir'])) {
            return;
        }
        $appGitIgnore = $this->appRoot . '/.gitignore';
        if (!file_exists($appGitIgnore) && !file_exists($this->settings['sourceDir'] . '/.gitignore')) {
            $this->output->writeln("Creating a .gitignore file");
            copy($source, $appGitIgnore);
        }
    }

}
