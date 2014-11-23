<?php

namespace CommerceGuys\Platform\Cli\Helper;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;

interface ShellHelperInterface {

    /**
     * Run a shell command, suppressing errors.
     *
     * @param string[] $args
     * @param bool $mustRun
     *
     * @throws ProcessFailedException If $mustRun is enabled and the command fails.
     *
     * @return string|bool
     *   The command's output or true on success, false on failure.
     */
    public function execute(array $args, $mustRun = false);

    /**
     * @param OutputInterface $output
     */
    public function setOutput(OutputInterface $output = null);

    /**
     * @param string $dir
     */
    public function setWorkingDirectory($dir);

    /**
     * @param string $level
     * @param string $message
     */
    public function log($level, $message);

}
