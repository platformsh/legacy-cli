<?php

declare(strict_types=1);

namespace Platformsh\Cli\Local;

use Platformsh\Cli\Local\DependencyManager\Pip;
use Platformsh\Cli\Local\DependencyManager\Npm;
use Platformsh\Cli\Local\DependencyManager\Bundler;
use Platformsh\Cli\Local\DependencyManager\Composer;
use Platformsh\Cli\Local\DependencyManager\DependencyManagerInterface;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Output\OutputInterface;

class DependencyInstaller
{
    public function __construct(protected OutputInterface $output, protected Shell $shell) {}

    /**
     * Modify the environment to make the installed dependencies available.
     *
     * @param string $destination
     * @param array<string, mixed> $dependencies
     */
    public function putEnv(string $destination, array $dependencies): void
    {
        $env = [];
        $paths = [];
        foreach ($dependencies as $stack => $stackDependencies) {
            $manager = $this->getManager($stack);
            $path = $destination . '/' . $stack;
            $paths = array_merge($paths, $manager->getBinPaths($path));
            $env = array_merge($env, $manager->getEnvVars($path));
        }
        $paths = array_filter(array_map('realpath', $paths));
        if (!empty($paths)) {
            $pathVariable = OsUtil::isWindows() ? 'Path' : 'PATH';
            $env[$pathVariable] = implode(':', $paths);
            if (getenv($pathVariable)) {
                $env[$pathVariable] .= ':' . getenv($pathVariable);
            }
        }
        foreach ($env as $name => $value) {
            putenv($name . '=' . $value);
        }
    }

    /**
     * Installs dependencies into a directory.
     *
     * @param string $destination
     * @param array<string, mixed> $dependencies
     * @param bool $global
     *
     * @return bool
     *     False if a dependency manager is not available; otherwise true.
     *
     * @throws \Exception If a dependency fails to install.
     */
    public function installDependencies(string $destination, array $dependencies, bool $global = false): bool
    {
        $success = true;
        foreach ($dependencies as $stack => $stackDependencies) {
            $manager = $this->getManager($stack);
            $this->output->writeln(sprintf(
                "Installing <info>%s</info> dependencies with '%s': %s",
                $stack,
                $manager->getCommandName(),
                implode(', ', array_keys($stackDependencies)),
            ));
            if (!$manager->isAvailable()) {
                $this->output->writeln(sprintf(
                    "Cannot install <comment>%s</comment> dependencies: '%s' is not installed.",
                    $stack,
                    $manager->getCommandName(),
                ));
                if ($manager->getInstallHelp()) {
                    $this->output->writeln($manager->getInstallHelp());
                }
                $success = false;
                continue;
            }
            $path = $destination . '/' . $stack;
            $this->ensureDirectory($path);
            $manager->install($path, $stackDependencies, $global);
        }

        return $success;
    }

    protected function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0o755, true)) {
            throw new \RuntimeException('Failed to create directory: ' . $path);
        }
    }

    /**
     * Finds the right dependency manager for a given stack.
     *
     * @param string $name
     *
     * @return DependencyManagerInterface
     */
    protected function getManager(string $name): DependencyManagerInterface
    {
        // Python has 'python', 'python2', and 'python3'.
        if (str_starts_with($name, 'python')) {
            return new Pip($this->shell, $name);
        }

        $stacks = [
            'nodejs' => new Npm($this->shell),
            'ruby' => new Bundler($this->shell),
            'php' => new Composer($this->shell),
        ];
        if (isset($stacks[$name])) {
            return $stacks[$name];
        }

        throw new \InvalidArgumentException(sprintf('Unknown dependencies stack: %s', $name));
    }
}
