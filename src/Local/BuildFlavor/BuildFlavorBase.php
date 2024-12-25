<?php

declare(strict_types=1);

namespace Platformsh\Cli\Local\BuildFlavor;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\Git;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Local\LocalApplication;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

abstract class BuildFlavorBase implements BuildFlavorInterface
{
    /**
     * Files from the app root to ignore during install.
     *
     * @var string[]
     */
    protected array $ignoredFiles = [];

    /**
     * Special destinations for installation.
     *
     * @var array<string, string>
     *   An array of filenames in the app root, mapped to destinations. The
     *   destinations are filenames supporting the replacements:
     *     "{webroot}" - see getWebRoot() (usually /app/public on Platform.sh)
     *     "{approot}" - the $buildDir (usually /app on Platform.sh)
     */
    protected array $specialDestinations;

    protected ?LocalApplication $app = null;
    /** @var array<string, mixed> $settings */
    protected array $settings = [];
    protected string $buildDir = '.';
    protected bool $copy = false;

    protected OutputInterface $output;
    protected OutputInterface $stdErr;

    protected Filesystem $fsHelper;
    protected Git $gitHelper;
    protected Shell $shellHelper;

    protected ?Config $config = null;

    protected ?string $appRoot = null;

    private ?string $documentRoot = null;

    /**
     * Whether all app files have just been symlinked or copied to the build.
     */
    private bool $buildInPlace = false;

    public function __construct(?Filesystem $fsHelper = null, ?Shell $shellHelper = null, ?Git $gitHelper = null)
    {
        $this->shellHelper = $shellHelper ?: new Shell();
        $this->fsHelper = $fsHelper ?: new Filesystem($this->shellHelper);
        $this->gitHelper = $gitHelper ?: new Git($this->shellHelper);

        $this->stdErr = $this->output = new NullOutput();

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
    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
        $this->stdErr = $output instanceof ConsoleOutputInterface
            ? $output->getErrorOutput()
            : $output;
        $this->shellHelper->setOutput($output);
    }

    /**
     * @inheritdoc
     */
    public function addIgnoredFiles(array $ignoredFiles): void
    {
        $this->ignoredFiles = array_merge($this->ignoredFiles, $ignoredFiles);
    }

    /**
     * @inheritdoc
     */
    public function prepare(string $buildDir, LocalApplication $app, Config $config, array $settings = []): void
    {
        $this->app = $app;
        $this->appRoot = $app->getRoot();
        $this->documentRoot = $app->getDocumentRoot();
        $this->settings = $settings;
        $this->config = $config;

        $this->fsHelper->setCopyOnWindows($this->config->getBool('local.copy_on_windows'));
        $this->ignoredFiles[] = $this->config->getStr('local.web_root');

        $this->setBuildDir($buildDir);

        if (!empty($settings['clone'])) {
            $settings['copy'] = true;
        }

        $this->copy = !empty($settings['copy']);
        $this->fsHelper->setRelativeLinks(empty($settings['abslinks']));
    }

    /**
     * {@inheritdoc}
     */
    public function setBuildDir(string $buildDir): void
    {
        $this->buildDir = $buildDir;
    }

    /**
     * Process the defined special destinations.
     */
    protected function processSpecialDestinations(): void
    {
        foreach ($this->specialDestinations as $sourcePattern => $relDestination) {
            $matched = glob($this->appRoot . '/' . $sourcePattern, GLOB_NOSORT);
            if (!$matched) {
                continue;
            }
            if ($relDestination === '{webroot}' && $this->buildInPlace) {
                continue;
            }

            // On Platform.sh these replacements would be a bit different.
            $absDestination = str_replace(
                ['{webroot}', '{approot}'],
                [$this->getWebRoot(), $this->buildDir],
                $relDestination,
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
                    $this->stdErr->writeln("Copying $relSource to $relDestination");
                } else {
                    $this->stdErr->writeln("Symlinking $relSource to $relDestination");
                }
                // Delete existing files, emitting a warning.
                if (file_exists($destination)) {
                    $this->stdErr->writeln(
                        sprintf(
                            "Overriding existing path '%s' in destination",
                            str_replace($this->buildDir . '/', '', $destination),
                        ),
                    );
                    $this->fsHelper->remove($destination);
                }
                if ($this->copy) {
                    $this->fsHelper->copy($source, $destination);
                } else {
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
     * @return string
     */
    protected function getSharedDir(): string
    {
        $shared = $this->app->getSourceDir() . '/' . $this->config->getStr('local.shared_dir');
        if (!$this->app->isSingle()) {
            $shared .= '/' . preg_replace('/[^a-z0-9\-_]+/i', '-', (string) $this->app->getName());
        }
        $this->fsHelper->mkdir($shared);

        return $shared;
    }

    /**
     * @inheritdoc
     */
    public function getWebRoot(): string
    {
        return $this->buildDir . '/' . $this->documentRoot;
    }

    /**
     * @return string
     */
    public function getAppDir(): string
    {
        return $this->buildDir;
    }

    /**
     * Copy, or symlink, files from the app root to the build directory.
     *
     * @return string
     *   The absolute path to the build directory where files have been copied.
     */
    protected function copyToBuildDir(): string
    {
        $this->buildInPlace = true;
        $buildDir = $this->buildDir;
        if ($this->app->shouldMoveToRoot()) {
            $buildDir .= '/' . $this->documentRoot;
        }
        if (!empty($this->settings['clone'])) {
            $this->cloneToBuildDir($buildDir);
        } elseif ($this->copy) {
            $this->fsHelper->copyAll($this->appRoot, $buildDir, $this->ignoredFiles, true);
        } else {
            $this->fsHelper->symlink($this->appRoot, $buildDir);
        }

        return $buildDir;
    }

    /**
     * Clone the app to the build directory via Git.
     *
     * @param string $buildDir
     */
    private function cloneToBuildDir(string $buildDir): void
    {
        $gitRoot = (string) $this->gitHelper->getRoot($this->appRoot, true);
        $ref = (string) $this->gitHelper->execute(['rev-parse', 'HEAD'], $gitRoot, true);

        $cloneArgs = ['--recursive', '--shared'];
        $tmpRepo = $buildDir . '-repo';
        if (file_exists($tmpRepo)) {
            $this->fsHelper->remove($tmpRepo, true);
        }
        $this->gitHelper->cloneRepo($gitRoot, $tmpRepo, $cloneArgs, true);
        $this->gitHelper->checkOut($ref, $tmpRepo, true, true);
        $this->fsHelper->remove($tmpRepo . '/.git');

        $appDir = $tmpRepo . '/' . substr($this->appRoot, strlen($gitRoot));
        if (!rename($appDir, $buildDir)) {
            throw new \RuntimeException(sprintf('Failed to move app from %s to %s', $appDir, $buildDir));
        }
        $this->fsHelper->remove($tmpRepo);
    }

    /**
     * @inheritdoc
     */
    public function install(): void
    {
        $this->processSharedFileMounts();
    }

    /**
     * Process shared file mounts in the application.
     *
     * For each "mount", this creates a corresponding directory in the project's
     * shared files directory, and symlinks it into the appropriate path in the
     * build.
     */
    protected function processSharedFileMounts(): void
    {
        $sharedDir = $this->getSharedDir();

        // If the build directory is a symlink, then skip, so that we don't risk
        // modifying the user's repository.
        if (is_link($this->buildDir)) {
            return;
        }

        $sharedFileMounts = $this->app->getSharedFileMounts();
        if (empty($sharedFileMounts)) {
            return;
        }

        $sharedDirRelative = $this->config->getStr('local.shared_dir');
        $this->stdErr->writeln('Creating symbolic links to mimic shared file mounts');
        foreach ($sharedFileMounts as $appPath => $sharedPath) {
            $target = $sharedDir . '/' . $sharedPath;
            $targetRelative = $sharedDirRelative . '/' . $sharedPath;
            $link = $this->buildDir . '/' . $appPath;
            if (file_exists($link) && !is_link($link)) {
                $this->stdErr->writeln('  Removing existing file <comment>' . $appPath . '</comment>');
                $this->fsHelper->remove($link);
            }
            if (!file_exists($target)) {
                $this->fsHelper->mkdir($target, 0o775);
            }
            $this->stdErr->writeln(
                '  Symlinking <info>' . $appPath . '</info> to <info>' . $targetRelative . '</info>',
            );
            $this->fsHelper->symlink($target, $link);
        }
    }

    /**
     * Create a settings.local.php for a Drupal site.
     *
     * This helps with database setup, etc.
     */
    protected function installDrupalSettingsLocal(): void
    {
        $sitesDefault = $this->getWebRoot() . '/sites/default';
        $shared = $this->getSharedDir();
        $settingsLocal = $sitesDefault . '/settings.local.php';

        if (is_dir($sitesDefault) && !file_exists($settingsLocal)) {
            $sharedSettingsLocal = $shared . '/settings.local.php';
            $relative = $this->config->getStr('local.shared_dir') . '/settings.local.php';
            if (!file_exists($sharedSettingsLocal)) {
                $this->stdErr->writeln("Creating file: <info>$relative</info>");
                $this->fsHelper->copy(CLI_ROOT . '/resources/drupal/settings.local.php.dist', $sharedSettingsLocal);
                $this->stdErr->writeln(
                    'Edit this file to add your database credentials and other Drupal configuration.',
                );
            } else {
                $this->stdErr->writeln("Symlinking <info>$relative</info> into sites/default");
            }
            $this->fsHelper->symlink($sharedSettingsLocal, $settingsLocal);
        }
    }

    /**
     * @inheritdoc
     */
    public function getKeys(): array
    {
        return ['default'];
    }

    /**
     * @inheritdoc
     */
    public function canArchive(): bool
    {
        return !$this->buildInPlace || $this->copy;
    }

    /**
     * Create a default .gitignore file for the app.
     *
     * @param string $source The path to a default .gitignore file, relative to
     *                       the 'resources' directory.
     */
    protected function copyGitIgnore(string $source): void
    {
        $source = CLI_ROOT . '/resources/' . $source;
        $gitRoot = $this->gitHelper->getRoot($this->appRoot);
        if (!$gitRoot) {
            return;
        }
        $appGitIgnore = $this->appRoot . '/.gitignore';
        if (!file_exists($appGitIgnore) && !file_exists($gitRoot . '/.gitignore')) {
            $this->stdErr->writeln("Creating a .gitignore file");
            copy($source, $appGitIgnore);
        }
    }
}
