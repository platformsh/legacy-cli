<?php

namespace Platformsh\Cli\Model\Host;

/**
 * Represents a host that can be accessed.
 *
 * This may be a remote host (via SSH) or even a local host.
 */
interface HostInterface
{
    /**
     * @return string A human-readable label for the host.
     */
    public function getLabel();

    /**
     * @return string A key that identifies the host, for caching purposes.
     */
    public function getCacheKey();

    /**
     * @return string
     *   The RFC3339 timestamp when the host last changed, for
     *   caching purposes, or an empty string if unknown.
     */
    public function lastChanged();

    /**
     * Runs a command on the host.
     *
     * @param string $command
     * @param bool $mustRun
     * @param bool $quiet
     * @param string|null $input
     *
     * @return string|true
     *   The command's output, or true if it succeeds with no output, or false
     *   if it fails and $mustRun is false.
     *
     * @throws \Symfony\Component\Process\Exception\RuntimeException
     *   If $mustRun is enabled and the command fails.
     */
    public function runCommand($command, $mustRun = true, $quiet = true, $input = null);

    /**
     * Runs a command using the current STDIN, STDOUT and STDERR.
     *
     * @param string $commandLine The command to run.
     * @param string $append      Anything to append to the command after it's
     *                            been wrapped to run on the host, e.g. pipe or
     *                            redirection syntax like ' > filename.txt';
     *
     * @return int The command's exit code.
     */
    public function runCommandDirect($commandLine, $append = '');
}
