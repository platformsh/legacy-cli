<?php
namespace Platformsh\Cli\Local\DependencyManager;

interface DependencyManagerInterface
{
    /**
     * @return string
     */
    public function getCommandName();

    /**
     * @return bool
     */
    public function isAvailable();

    /**
     * @return string
     */
    public function getInstallHelp();

    /**
     * @param string $path The path prefix for the dependencies.
     *
     * @param array $dependencies An associative array of dependencies with
     *                            their versions.
     */
    public function install($path, array $dependencies);

    /**
     * @param string $path The path prefix for the dependencies.
     *
     * @return array An array of absolute paths to "bin" directories.
     */
    public function getBinPaths($path);

    /**
     * @param string $path The path prefix for the dependencies.
     *
     * @return array An associative array of environment variables.
     */
    public function getEnvVars($path);
}
