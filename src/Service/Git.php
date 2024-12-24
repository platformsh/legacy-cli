<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Exception\DependencyMissingException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\RuntimeException;

/**
 * Helper class which runs Git CLI commands and interprets the results.
 */
class Git
{
    private readonly Shell $shell;

    private ?string $repositoryDir = null;
    private ?string $sshCommandFile = null;
    /** @var string[] */
    private array $extraSshOptions = [];

    /**
     * @param Shell|null $shell
     * @param Ssh|null   $ssh
     */
    public function __construct(?Shell $shell = null, private readonly ?Ssh $ssh = null)
    {
        $this->shell = $shell ?: new Shell();
    }

    /**
     * Get the installed version of the Git CLI.
     *
     * @return string|false
     *   The version number, or false on failure.
     */
    private function getVersion(): false|string
    {
        static $version;
        if (!$version) {
            $version = false;
            $string = $this->execute(['--version'], false);
            if (is_string($string) && preg_match('/(^| )([0-9]+[^ ]*)/', $string, $matches)) {
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
    public function ensureInstalled(): void
    {
        try {
            $this->execute(['--version'], null, true);
        } catch (ProcessFailedException $e) {
            if ($this->shell->exceptionMeansCommandDoesNotExist($e)) {
                throw new DependencyMissingException('Git must be installed', $e);
            }
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
    public function setDefaultRepositoryDir(string $dir): void
    {
        $this->repositoryDir = $dir;
    }

    /**
     * Gets the current branch name.
     *
     * @param string|null $dir
     *   The path to a Git repository.
     * @param bool   $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return string|false
     */
    public function getCurrentBranch(?string $dir = null, bool $mustRun = false): string|false
    {
        $args = ['symbolic-ref', '--short', 'HEAD'];

        return $this->execute($args, $dir, $mustRun);
    }

    /**
     * Execute a Git command.
     *
     * @param string[] $args
     *   Command arguments (everything after 'git').
     * @param string|false|null $dir
     *   The path to a Git repository. Set to false if the command should not
     *   run inside a repository. Set to null to use the default repository.
     * @param bool $mustRun
     *   Enable exceptions if the Git command fails.
     * @param bool $quiet
     *   Suppress command output.
     * @param array<string, string> $env
     * @param bool $online
     * @param string $uri
     *
     * @return string|false
     *   The command output or false if the command fails.
     *@throws RuntimeException
     *   If the command fails and $mustRun is enabled.
     *
     */
    public function execute(array $args, string|false|null $dir = null, bool $mustRun = false, bool $quiet = true, array $env = [], bool $online = false, string $uri = ''): string|false
    {
        // If enabled, set the working directory to the repository.
        $dir = $dir !== false ? ($dir ?: $this->repositoryDir) : null;
        // Set up SSH, if the Git command might connect to a remote.
        if ($online) {
            $env += $this->setupSshEnv($uri);
        }
        // Run the command.
        array_unshift($args, 'git');

        return $this->shell->execute($args, $dir, $mustRun, $quiet, $env);
    }

    /**
     * Executes a Git command and returns its output, throwing an exception on failure.
     *
     * @param string[] $args Command arguments (everything after 'git').
     * @param array<string, string> $env
     *
     * @return string The command output.
     * @throws RuntimeException If the command fails.
     */
    public function mustExecute(array $args, string|false|null $dir = null, bool $quiet = true, array $env = [], bool $online = false, string $uri = ''): string
    {
        return (string) $this->execute($args, $dir, true, $quiet, $env, $online, $uri);
    }

    /**
     * Creates a Git repository in a directory.
     *
     * @param bool $mustRun
     *   Enable exceptions if the Git command fails.
     */
    public function init(string $dir, string $initial_branch = '', bool $mustRun = false): bool
    {
        if (is_dir($dir . '/.git')) {
            throw new \InvalidArgumentException("Already a repository: $dir");
        }

        $args = ['init'];
        if ($initial_branch !== '' && $this->supportsGitInitialBranchFlag()) {
            $args[] = "--initial-branch=$initial_branch";
        }
        return $this->execute($args, $dir, $mustRun, false) !== false;
    }

    /**
     * Checks whether a remote repository exists.
     *
     * @param string $url
     * @param ?string $ref
     * @param bool $heads Whether to limit to heads only.
     *
     * @return bool
     *
     * @throws RuntimeException
     *   If the Git command fails.
     */
    public function remoteRefExists(string $url, ?string $ref = null, bool $heads = true): bool
    {
        $args = ['ls-remote', $url];
        if ($heads) {
            $args[] = '--heads';
        }
        if ($ref !== null) {
            $args[] = $ref;
        }
        $result = $this->mustExecute($args, dir: false, online: true, uri: $url);

        return strlen($result) > 0;
    }

    /**
     * Check whether a branch exists.
     *
     * @param string $branchName
     *   The branch name.
     * @param ?string $dir
     *   The path to a Git repository.
     * @param bool $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return bool
     */
    public function branchExists(string $branchName, ?string $dir = null, bool $mustRun = false): bool
    {
        // The porcelain command 'git branch' is less strict about character
        // encoding than (otherwise simpler) plumbing commands such as
        // 'git show-ref'.
        $result = $this->execute(['branch', '--list', '--no-color', '--no-column'], $dir, $mustRun);
        if ($result === false) {
            return false;
        }

        $branches = array_map(fn($line) => trim(ltrim($line, '* ')), explode("\n", $result));

        return in_array($branchName, $branches, true);
    }

    /**
     * Checks whether a branch exists on a remote.
     */
    public function remoteBranchExists(string $remote, string $branchName, ?string $dir = null, bool $mustRun = false): bool
    {
        $args = ['ls-remote', $remote, $branchName];
        $result = $this->execute($args, $dir, $mustRun, true, [], true);

        return is_string($result) && strlen(trim($result));
    }

    /**
     * Create a new branch and check it out.
     *
     * @param string $name
     * @param string|null $parent
     * @param string|null $upstream
     * @param string|null $dir The path to a Git repository.
     * @param bool $mustRun Enable exceptions if the Git command fails.
     *
     * @return bool
     */
    public function checkOutNew(string $name, ?string $parent = null, ?string $upstream = null, ?string $dir = null, bool $mustRun = false): bool
    {
        $args = ['checkout', '-b', $name];
        if ($parent !== null) {
            $args[] = $parent;
        } elseif ($upstream !== null) {
            $args[] = '--track';
            $args[] = $upstream;
        }

        return $this->execute($args, $dir, $mustRun, false) !== false;
    }

    /**
     * Fetches from the Git remote.
     */
    public function fetch(string $remote, ?string $branch = null, string $uri = '', ?string $dir = null, bool $mustRun = false): bool
    {
        $args = ['fetch', $remote];
        if ($branch !== null) {
            $args[] = $branch;
        }

        return $this->execute($args, $dir, $mustRun, false, [], true, $uri) !== false;
    }

    /**
     * Pulls a ref from a repository (runs "git pull").
     */
    public function pull(?string $repository = null, ?string $ref = null, ?string $dir = null, bool $mustRun = true, bool $quiet = false): bool
    {
        $args = ['pull'];
        if ($repository !== null) {
            $args[] = $repository;
        }
        if ($ref !== null) {
            $args[] = $ref;
        }

        return $this->execute($args, $dir, $mustRun, $quiet, [], true, $repository) !== false;
    }

    /**
     * Checks out a branch.
     *
     * @param string $name
     * @param string|null $dir
     *   The path to a Git repository.
     * @param bool $mustRun
     *   Enable exceptions if the Git command fails.
     * @param bool $quiet
     *
     * @return bool
     */
    public function checkOut(string $name, ?string $dir = null, bool $mustRun = false, bool $quiet = false): bool
    {
        return $this->execute([
            'checkout',
            $name,
        ], $dir, $mustRun, $quiet) !== false;
    }

    /**
     * Get the upstream for a branch.
     *
     * @param ?string $branch
     *   The name of the branch to get the upstream for. Defaults to the current
     *   branch.
     * @param string|null $dir
     *   The path to a Git repository.
     * @param bool $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return string|false
     *   The upstream, in the form remote/branch, or false if no upstream is
     *   found.
     */
    public function getUpstream(?string $branch = null, ?string $dir = null, bool $mustRun = false): string|false
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
     * Sets the upstream for the current branch.
     *
     * @param string|false $upstream
     *   The upstream name, or false to unset the upstream.
     * @param string|null  $branch
     *   The branch to act on (defaults to the current branch).
     * @param string|null  $dir
     *   The path to a Git repository.
     * @param bool $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return bool
     */
    public function setUpstream(string|false $upstream, ?string $branch = null, ?string $dir = null, bool $mustRun = false): bool
    {
        $args = ['branch'];
        if ($upstream !== false) {
            $args[] = '--set-upstream-to=' . $upstream;
        } else {
            $args[] = '--unset-upstream';
        }
        if ($branch !== null) {
            $args[] = $branch;
        }

        return $this->execute($args, $dir, $mustRun) !== false;
    }

    /**
     * @return bool
     */
    public function supportsGitSshCommand(): bool
    {
        return version_compare($this->getVersion() ?: '', '2.3', '>=');
    }

    /**
     * @return bool
     */
    public function supportsGitInitialBranchFlag(): bool
    {
        return version_compare($this->getVersion() ?: '', '2.28', '>=');
    }

    /**
     * Clone a repository.
     *
     * A ProcessFailedException will be thrown if the command fails.
     *
     * @param string $urlOrPath
     *   The Git repository URL or file path.
     * @param ?string $destination
     *   A directory name to clone into.
     * @param string[] $args
     *   Extra arguments for the Git command.
     * @param bool $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return bool
     */
    public function cloneRepo(string $urlOrPath, ?string $destination = null, array $args = [], bool $mustRun = false): bool
    {
        $args = array_merge(['clone', $urlOrPath], $args);
        if ($destination) {
            $args[] = $destination;
        }

        return $this->execute($args, false, $mustRun, false, [], $urlOrPath[0] !== '/', $urlOrPath) !== false;
    }

    /**
     * Find the root directory of a Git repository.
     *
     * Uses PHP rather than the Git CLI.
     *
     * @param string|null $dir
     *   The starting directory (defaults to the current working directory).
     * @param bool $mustRun
     *   Causes an exception to be thrown if the directory is not a repository.
     *
     * @return string|false
     */
    public function getRoot(?string $dir = null, bool $mustRun = false): string|false
    {
        $dir = $dir ?: getcwd();
        if ($dir === false) {
            return false;
        }

        $current = $dir;
        while (true) {
            if (is_dir($current . '/.git')) {
                return realpath($current) ?: $current;
            }

            $parent = dirname($current);
            if ($parent === $current || $parent === '.') {
                break;
            }
            $current = $parent;
        }

        if ($mustRun) {
            throw new \RuntimeException('Not a git repository');
        }

        return false;
    }

    /**
     * Checks whether a file is excluded via .gitignore or similar configuration.
     */
    public function checkIgnore(string $file, ?string $dir = null): bool
    {
        return $this->execute(['check-ignore', $file], $dir) !== false;
    }

    /**
     * Update and/or initialize submodules.
     *
     * @param bool $recursive
     *   Whether to recurse into nested submodules.
     * @param string|null $dir
     *   The path to a Git repository.
     * @param bool $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return bool
     */
    public function updateSubmodules(bool $recursive = false, ?string $dir = null, bool $mustRun = false): bool
    {
        $args = ['submodule', 'update', '--init'];
        if ($recursive) {
            $args[] = '--recursive';
        }

        return $this->execute($args, $dir, $mustRun, false) !== false;
    }

    /**
     * Reads a configuration item.
     *
     * @param string $key
     *   A Git configuration key.
     * @param string|null $dir
     *   The path to a Git repository.
     * @param bool $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return string|false
     */
    public function getConfig(string $key, ?string $dir = null, bool $mustRun = false): string|false
    {
        $args = ['config', '--get', $key];

        return $this->execute($args, $dir, $mustRun);
    }

    /**
     * Sets extra options to pass to the underlying SSH command.
     *
     * @param string[] $options
     */
    public function setExtraSshOptions(array $options): void
    {
        $this->extraSshOptions = $options;
    }

    /**
     * Initializes SSH environment variables for Git.
     *
     * This will use GIT_SSH_COMMAND if supported, or GIT_SSH (and a temporary
     * file) otherwise.
     *
     * @param string $gitUri
     *
     * @return array<string, string>
     */
    public function setupSshEnv(string $gitUri): array
    {
        if (!isset($this->ssh)) {
            return [];
        }
        $sshCommand = $this->ssh->getSshCommand($gitUri, $this->extraSshOptions, null, true);
        if (empty($sshCommand) || $sshCommand === 'ssh') {
            return [];
        }
        if (!$this->supportsGitSshCommand()) {
            $contents = $sshCommand . ' "$@"' . "\n";
            if (!isset($this->sshCommandFile) || \file_get_contents($this->sshCommandFile) !== $contents) {
                $this->sshCommandFile = $this->writeSshFile($contents);
            }
            return ['GIT_SSH' => $this->sshCommandFile] + $this->ssh->getEnv();
        }
        return ['GIT_SSH_COMMAND' => $sshCommand] + $this->ssh->getEnv();
    }

    /**
     * Write an SSH command to a temporary file to be used with GIT_SSH.
     *
     * @param string $sshCommand
     *
     * @return string
     */
    private function writeSshFile(string $sshCommand): string
    {
        $tempDir = sys_get_temp_dir();
        $tempFile = tempnam($tempDir, 'cli-git-ssh');
        if (!$tempFile) {
            throw new \RuntimeException('Failed to create temporary file in: ' . $tempDir);
        }
        if (!file_put_contents($tempFile, trim($sshCommand) . "\n")) {
            throw new \RuntimeException('Failed to write temporary file: ' . $tempFile);
        }
        if (!chmod($tempFile, 0o750)) {
            throw new \RuntimeException('Failed to make temporary SSH command file executable: ' . $tempFile);
        }

        return $tempFile;
    }

    public function __destruct()
    {
        if (isset($this->sshCommandFile)) {
            if (@unlink($this->sshCommandFile)) {
                unset($this->sshCommandFile);
            }
        }
    }
}
