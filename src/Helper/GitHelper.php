<?php

namespace CommerceGuys\Platform\Cli\Helper;

use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\ProcessBuilder;

class GitHelper extends Helper
{

    /** @var string */
    protected $repositoryDir = '.';

    /** @var OutputInterface|false */
    protected $output;

    /**
     * @inheritdoc
     */
    public function getName()
    {
        return 'git';
    }

    /**
     * @param OutputInterface|false $output
     */
    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
    }

    /**
     * @param int    $type
     * @param string $buffer
     */
    public function log($type, $buffer)
    {
        if ($this->output) {
            $this->output->write($buffer);
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
        $args = array('symbolic-ref', '--short', 'HEAD');

        return $this->execute($args, $dir, $mustRun);
    }

    /**
     * Get the working directory for a command.
     *
     * @param string|false $override
     *   The repository dir, or false if the command should not run inside the
     *   repository.
     *
     * @see setDefaultRepositoryDir()
     *
     * @return string|null
     */
    protected function getWorkingDir($override = null)
    {
        $dir = null;
        if ($override !== false) {
            $dir = $override ?: $this->repositoryDir;
            if (!$this->isRepository($dir)) {
                throw new \RuntimeException("Not a Git repository: " . $dir);
            }
        }
        return $dir;
    }

    /**
     * Execute a Git command.
     *
     * @param string|false $dir
     *   The path to a Git repository. Set to false if the command should not
     *   run inside a repository.
     * @param string[]     $args
     *   Command arguments (everything after 'git').
     * @param bool         $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @throws \RuntimeException If the repository directory is invalid.
     * @throws ProcessFailedException If $mustRun is enabled and the command fails.
     *
     * @return string|bool
     */
    public function execute(array $args, $dir = null, $mustRun = false)
    {
        // Build the process.
        if (reset($args) != 'git') {
            array_unshift($args, 'git');
        }
        $processBuilder = new ProcessBuilder($args);
        $process = $processBuilder->getProcess();
        // If enabled, set the working directory to the repository.
        $process->setWorkingDirectory($this->getWorkingDir($dir));
        // Run the command.
        try {
            $process->mustRun(array($this, 'log'));
        } catch (ProcessFailedException $e) {
            if (!$mustRun) {
                return false;
            }
            throw $e;
        }
        $output = $process->getOutput();

        return $output ? rtrim($output) : true;
    }

    /**
     * @param string $dir
     *
     * @return bool
     */
    protected function isRepository($dir)
    {
        return is_dir($dir . '/.git');
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
        $args = array('show-ref', "refs/heads/$branchName");

        return (bool) $this->execute($args, $dir, $mustRun);
    }

    /**
     * Create a new branch.
     *
     * @param string $name
     * @param string $parent
     * @param string $dir
     *   The path to a Git repository.
     * @param bool   $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return bool
     */
    public function branch($name, $parent = 'master', $dir = null, $mustRun = false)
    {
        return (bool) $this->execute(array('checkout', '-b', $name, $parent), $dir, $mustRun);
    }

    /**
     * Check out a branch.
     *
     * @param string $name
     * @param string $dir
     *   The path to a Git repository.
     * @param bool   $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return bool
     */
    public function checkOut($name, $dir = null, $mustRun = false)
    {
        return (bool) $this->execute(array('checkout', $name), $dir, $mustRun);
    }

    /**
     * Get the upstream for the current branch.
     *
     * @param string $dir
     *   The path to a Git repository.
     * @param bool   $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return string|false
     */
    public function getUpstream($dir = null, $mustRun = false)
    {
        $args = array('rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}');

        return $this->execute($args, $dir, $mustRun);
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
     * @param string $branch
     *   The name of a branch to clone.
     * @param bool   $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return bool
     */
    public function cloneRepo($url, $destination = null, $branch = null, $mustRun = false)
    {
        $args = array('clone', $url);
        if ($destination) {
            $args[] = $destination;
        }
        if ($branch) {
            $args[] = '--branch';
            $args[] = $branch;
        }

        return (bool) $this->execute($args, false, $mustRun);
    }

    /**
     * Read a configuration item.
     *
     * @param string $key
     *   A Git configuration key.
     * @param string $dir
     *   The path to a Git repository.
     * @param bool   $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return string
     */
    public function getConfig($key, $dir = null, $mustRun = false)
    {
        $args = array('config', '--get', $key);

        return (bool) $this->execute($args, $dir, $mustRun);
    }

}
