<?php

namespace CommerceGuys\Platform\Cli\Helper;

use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

class ShellHelper extends Helper {

    public function getName()
    {
        return 'shell';
    }

    /**
     * Run a shell command in the current directory, suppressing errors.
     *
     * @param string $cmd The command, suitably escaped.
     * @param string &$error Optionally use this to capture errors.
     *
     * @return string The command output.
     */
    public function execute($cmd, &$error = '')
    {
        $process = new Process($cmd);
        $process->run();
        $error = $process->getErrorOutput();
        return $process->getOutput();
    }

    /**
     * Build and run a process.
     *
     * @param array $args
     * @param bool $mustRun
     *
     * @return string
     */
    public function executeArgs(array $args, $mustRun = false)
    {
        $builder = new ProcessBuilder($args);
        $process = $builder->getProcess();
        if ($mustRun) {
            $process->mustRun();
        }
        else {
            $process->run();
        }
        return $process->getOutput();
    }
}
