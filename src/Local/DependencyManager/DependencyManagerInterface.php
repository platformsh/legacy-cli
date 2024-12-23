<?php

declare(strict_types=1);

namespace Platformsh\Cli\Local\DependencyManager;

interface DependencyManagerInterface
{
    /**
     * Returns the command name of the dependency manager to be used.
     *
     * @return string
     */
    public function getCommandName(): string;

    /**
     * Checks whether the dependency manager itself is available (installed).
     *
     * @return bool
     */
    public function isAvailable(): bool;

    /**
     * Returns help for the user to install the dependency manager itself.
     *
     * @return string
     */
    public function getInstallHelp(): string;

    /**
     * Installs a list of dependencies.
     *
     * @param string $path        A path in which dependencies can be installed,
     *                            or (if $global is true) a path for running
     *                            commands and writing config files.
     * @param array<string, mixed> $dependencies An associative array of dependencies with
     *                            their versions.
     * @param bool $global        Whether to install dependencies globally for
     *                            the user or system (i.e. not attached to the
     *                            project).
     */
    public function install(string $path, array $dependencies, bool $global = false): void;

    /**
     * Returns a list of "bin" directories in which dependencies are installed.
     *
     * @param string $prefix The path prefix for the dependencies.
     *
     * @return string[] An array of absolute paths.
     */
    public function getBinPaths(string $prefix): array;

    /**
     * Returns a list of environment variables for using installed dependencies.
     *
     * @param string $path The path prefix for the dependencies.
     *
     * @return array<string, string> An associative array of environment variables.
     */
    public function getEnvVars(string $path): array;
}
