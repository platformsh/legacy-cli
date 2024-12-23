<?php

declare(strict_types=1);

namespace Platformsh\Cli\Model\Host;

use Symfony\Component\Process\Exception\RuntimeException;

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
    public function getLabel(): string;

    /**
     * @return string A key that identifies the host, for caching purposes.
     */
    public function getCacheKey(): string;

    /**
     * @return string
     *   The RFC3339 timestamp when the host last changed, for
     *   caching purposes, or an empty string if unknown.
     */
    public function lastChanged(): string;

    /**
     * Runs a command on the host.
     *
     * @return string|false
     *   The command's output, with trailing whitespace trimmed, or false on failure.
     *
     * @throws RuntimeException
     *   If $mustRun is enabled and the command fails.
     */
    public function runCommand(string $command, bool $mustRun = true, bool $quiet = true, ?string $input = null): string|false;

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
    public function runCommandDirect(string $commandLine, string $append = ''): int;
}
