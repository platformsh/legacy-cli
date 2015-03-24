<?php

namespace Platformsh\Cli\Helper;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;

interface ShellHelperInterface
{

    /**
     * Run a shell command, suppressing errors.
     *
     * @param string[]     $args
     * @param string|false $dir
     * @param bool         $mustRun
     * @param bool         $quiet
     *
     * @throws ProcessFailedException If $mustRun is enabled and the command fails.
     *
     * @return string|bool
     *   The command's output or true on success, false on failure.
     */
    public function execute(array $args, $dir = null, $mustRun = false, $quiet = false);

    /**
     * Check whether a shell command exists.
     *
     * @param string $command
     *
     * @return bool
     */
    public function commandExists($command);

}
