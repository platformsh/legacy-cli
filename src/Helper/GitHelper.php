<?php

namespace Platformsh\Cli\Helper;

use Platformsh\Cli\Console\OutputAwareInterface;
use Platformsh\Cli\Exception\DependencyMissingException;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Helper class which runs Git CLI commands and interprets the results.
 */
class GitHelper extends Helper implements OutputAwareInterface
{

    /** @var string */
    protected $repositoryDir = '.';

    /** @var ShellHelperInterface */
    protected $shellHelper;

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'git';
    }

    /**
     * Constructor.
     *
     * @param ShellHelperInterface|null $shellHelper
     */
    public function __construct(ShellHelperInterface $shellHelper = null)
    {
        $this->shellHelper = $shellHelper ?: new ShellHelper();
    }

    /**
     * {@inheritdoc}
     */
    public function setOutput(OutputInterface $output)
    {
        if ($this->shellHelper instanceof OutputAwareInterface) {
            $this->shellHelper->setOutput($output);
        }
    }

    /**
     * Get the installed version of the Git CLI.
     *
     * @return string|false
     *   The version number, or false on failure.
     */
    public function getVersion()
    {
        static $version;
        if (!$version) {
            $version = false;
            $string = $this->execute(['--version'], false);
            if ($string && preg_match('/(^| )([0-9]+[^ ]*)/', $string, $matches)) {
                $version = $matches[2];
            }
        }

        return $version;
    }

    /**
     * Ensure that the Git CLI is installed.
     *
     * @throws DependencyMissingException
     */
    public function ensureInstalled()
    {
        if (!$this->shellHelper->commandExists('git')) {
            throw new DependencyMissingException('Git must be installed');
        }
    }

    /**
     * Set the repository directory.
     *
     * The default is the current working directory.
     *
     * @param string $dir
     *   The path to a Git repository.
     */
    public function setDefaultRepositoryDir($dir)
    {
        $this->repositoryDir = $dir;
    }

    /**
     * Get the current branch name.
     *
     * @param string $dir
     *   The path to a Git repository.
     * @param bool   $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return string|false
     */
    public function getCurrentBranch($dir = null, $mustRun = false)
    {
        $args = ['symbolic-ref', '--short', 'HEAD'];

        return $this->execute($args, $dir, $mustRun);
    }

    /**
     * Get a list of branches merged with a specific ref.
     *
     * @param string $ref
     * @param bool   $remote
     * @param null   $dir
     * @param bool   $mustRun
     *
     * @return string[]
     */
    public function getMergedBranches($ref = 'HEAD', $remote = false, $dir = null, $mustRun = false)
    {
        $args = ['branch', '--list', '--no-column', '--merged', $ref];
        if ($remote) {
            $args[] = '--remote';
        }
        $mergedBranches = $this->execute($args, $dir, $mustRun);
        $array = array_map(
            function ($element) {
                return trim($element, ' *');
            },
            explode("\n", $mergedBranches)
        );

        return $array;
    }

    /**
     * Execute a Git command.
     *
     * @param string[]     $args
     *   Command arguments (everything after 'git').
     * @param string|false $dir
     *   The path to a Git repository. Set to false if the command should not
     *   run inside a repository.
     * @param bool         $mustRun
     *   Enable exceptions if the Git command fails.
     * @param bool         $quiet
     *   Suppress command output.
     *
     * @throws \Exception
     *   If the command fails and $mustRun is enabled.
     *
     * @return string|bool
     *   The command output, true if there is no output, or false if the command
     *   fails.
     */
    public function execute(array $args, $dir = null, $mustRun = false, $quiet = true)
    {
        // If enabled, set the working directory to the repository.
        if ($dir !== false) {
            $dir = $dir ?: $this->repositoryDir;
        }
        // Run the command.
        array_unshift($args, 'git');

        return $this->shellHelper->execute($args, $dir, $mustRun, $quiet);
    }

    /**
     * Check whether a directory is a Git repository.
     *
     * @param string $dir
     *
     * @return bool
     */
    public function isRepository($dir)
    {
        return is_dir($dir . '/.git');
    }

    /**
     * Create a Git repository in a directory.
     *
     * @param string $dir
     * @param bool   $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return bool
     */
    public function init($dir, $mustRun = false)
    {
        if ($this->isRepository($dir)) {
            throw new \InvalidArgumentException("Already a repository: $dir");
        }

        return (bool) $this->execute(['init'], $dir, $mustRun, false);
    }

    /**
     * Check whether a remote repository exists.
     *
     * @param string $url
     *
     * @throws \Exception
     *   If the Git command fails.
     *
     * @return bool
     */
    public function remoteRepoExists($url)
    {
        $result = $this->execute(['ls-remote', $url, 'HEAD'], false, true);

        return !is_bool($result) && strlen($result) > 0;
    }

    /**
     * Check whether a branch exists.
     *
     * @param string $branchName
     *   The branch name.
     * @param string $dir
     *   The path to a Git repository.
     * @param bool   $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return bool
     */
    public function branchExists($branchName, $dir = null, $mustRun = false)
    {
        $args = ['show-ref', "refs/heads/$branchName"];

        return (bool) $this->execute($args, $dir, $mustRun);
    }

    /**
     * Check whether a branch exists on a remote.
     *
     * @param string $remote
     * @param string $branchName
     * @param null   $dir
     * @param bool   $mustRun
     *
     * @return bool
     */
    public function remoteBranchExists($remote, $branchName, $dir = null, $mustRun = false)
    {
        $args = ['ls-remote', $remote, $branchName];
        $result = $this->execute($args, $dir, $mustRun);

        return is_string($result) && strlen(trim($result));
    }

    /**
     * Create a new branch and check it out.
     *
     * @param string      $name
     * @param string|null $parent
     * @param string|null $upstream
     * @param string|null $dir     The path to a Git repository.
     * @param bool        $mustRun Enable exceptions if the Git command fails.
     *
     * @return bool
     */
    public function checkOutNew($name, $parent = null, $upstream = null, $dir = null, $mustRun = false)
    {
        $args = ['checkout', '-b', $name];
        if ($parent !== null) {
            $args[] = $parent;
        } elseif ($upstream !== null) {
            $args[] = '--track';
            $args[] = $upstream;
        }

        return (bool) $this->execute($args, $dir, $mustRun, false);
    }

    /**
     * Fetch from the Git remote.
     *
     * @param string      $remote
     * @param string|null $branch
     * @param string|null $dir
     * @param bool        $mustRun
     *
     * @return bool
     */
    public function fetch($remote, $branch = null, $dir = null, $mustRun = false)
    {
        $args = ['fetch', $remote];
        if ($branch !== null) {
            $args[] = $branch;
        }

        return (bool) $this->execute($args, $dir, $mustRun, false);
    }

    /**
     * Check if a file is excluded from Git via .gitignore or another mechanism.
     *
     * @param string      $path
     * @param string|null $dir
     * @param bool        $mustRun
     *
     * @return bool
     *   True if the file is excluded, false otherwise.
     */
    public function checkIgnore($path, $dir = null, $mustRun = false)
    {
        $args = ['check-ignore', $path, '--quiet'];

        return (bool) $this->execute($args, $dir, $mustRun);
    }

    /**
     * Check out a branch.
     *
     * @param string      $name
     * @param string|null $dir
     *   The path to a Git repository.
     * @param bool        $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return bool
     */
    public function checkOut($name, $dir = null, $mustRun = false)
    {
        return (bool) $this->execute([
            'checkout',
            $name,
        ], $dir, $mustRun, false
        );
    }

    /**
     * Get the upstream for a branch.
     *
     * @param string      $branch
     *   The name of the branch to get the upstream for. Defaults to the current
     *   branch.
     * @param string|null $dir
     *   The path to a Git repository.
     * @param bool        $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return string|false
     *   The upstream, in the form remote/branch, or false if no upstream is
     *   found.
     */
    public function getUpstream($branch = null, $dir = null, $mustRun = false)
    {
        if ($branch === null) {
            $args = ['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}'];

            return $this->execute($args, $dir, $mustRun);
        }

        $remoteName = $this->getConfig("branch.$branch.remote", $dir, $mustRun);
        $remoteBranch = $this->getConfig("branch.$branch.merge", $dir, $mustRun);
        if (empty($remoteName) || empty($remoteBranch)) {
            return false;
        }

        return $remoteName . '/' . str_replace('refs/heads/', '', $remoteBranch);
    }

    /**
     * Set the upstream for the current branch.
     *
     * @param string|false $upstream
     *   The upstream name, or false to unset the upstream.
     * @param string|null  $dir
     *   The path to a Git repository.
     * @param bool         $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return bool
     */
    public function setUpstream($upstream, $dir = null, $mustRun = false)
    {
        $args = ['branch'];
        if ($upstream !== false) {
            $args[] = '--set-upstream-to=' . $upstream;
        }
        else {
            $args[] = '--unset-upstream';
        }

        return (bool) $this->execute($args, $dir, $mustRun);
    }

    /**
     * @return bool
     */
    public function supportsShallowClone()
    {
        return version_compare($this->getVersion(), '1.9', '>=');
    }

    /**
     * Clone a repository.
     *
     * A ProcessFailedException will be thrown if the command fails.
     *
     * @param string $url
     *   The Git repository URL.
     * @param string $destination
     *   A directory name to clone into.
     * @param array  $args
     *   Extra arguments for the Git command.
     * @param bool   $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return bool
     */
    public function cloneRepo($url, $destination = null, array $args = [], $mustRun = false)
    {
        $args = array_merge(['clone', $url], $args);
        if ($destination) {
            $args[] = $destination;
        }

        return (bool) $this->execute($args, false, $mustRun, false);
    }

    /**
     * Read a configuration item.
     *
     * @param string      $key
     *   A Git configuration key.
     * @param string|null $dir
     *   The path to a Git repository.
     * @param bool        $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return string|false
     */
    public function getConfig($key, $dir = null, $mustRun = false)
    {
        $args = ['config', '--get', $key];

        return $this->execute($args, $dir, $mustRun);
    }
}
