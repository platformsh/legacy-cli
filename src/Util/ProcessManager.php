<?php
namespace Platformsh\Cli\Util;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class ProcessManager
{
    /** @var Process[] */
    protected $processes;

    /**
     * @return bool
     */
    public static function supported()
    {
        return extension_loaded('pcntl') && extension_loaded('posix');
    }

    /**
     * Fork the PHP process.
     *
     * Code run after this method is run in a child process. The parent process
     * merely waits for a SIGCHLD (successful) or SIGTERM (error) signal from
     * the child.
     *
     * This depends on the PCNTL extension.
     */
    public static function fork()
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('Failed to fork PHP process');
        }
        elseif ($pid > 0) {
            // This is the parent process. If the child process succeeds, this
            // receives SIGCHLD. If it fails, this receives SIGTERM.
            declare (ticks = 1);
            pcntl_signal(SIGCHLD, function () {
                exit;
            });
            pcntl_signal(SIGTERM, function () {
                exit(1);
            });

            // Wait a reasonable amount of time for the child processes to
            // finish.
            sleep(60);
            throw new \RuntimeException('Timeout in parent process');
        }
        elseif (posix_setsid() === -1) {
            throw new \RuntimeException('Child process failed to become session leader');
        }
    }

    /**
     * Kill the parent process.
     *
     * @param bool $error
     *   Whether the parent process should exit with an error status.
     */
    public static function killParent($error = false)
    {
        if (!posix_kill(posix_getppid(), $error ? SIGTERM : SIGCHLD)) {
            throw new \RuntimeException('Failed to kill parent process');
        }
    }

    /**
     * @param Process $process
     * @param string $pidFile
     * @param OutputInterface $log
     *
     * @throws \Exception on failure
     *
     * @return int
     *   The process PID.
     */
    public function startProcess(Process $process, $pidFile, OutputInterface $log)
    {
        $this->processes[$pidFile] = $process;

        try {
            $process->start(function ($type, $buffer) use ($log) {
                $log->writeln($buffer);
            });
        }
        catch (\Exception $e) {
            unset($this->processes[$pidFile]);
            throw $e;
        }

        $pid = $process->getPid();
        if (file_put_contents($pidFile, $pid) === false) {
            throw new \RuntimeException('Failed to write PID file: ' . $pidFile);
        }

        $log->writeln(sprintf('Process started: %s', $process->getCommandLine()));

        return $pid;
    }

    /**
     * Monitor processes and stop them if their PID file no longer exists.
     *
     * @param OutputInterface $log
     *   A log file as a Symfony Console output object.
     */
    public function monitor(OutputInterface $log)
    {
        while (count($this->processes)) {
            sleep(1);
            foreach (array_keys($this->processes) as $pidFile) {
                if (!file_exists($pidFile)) {
                    $log->writeln(sprintf('Process stopped: %s', $this->processes[$pidFile]->getCommandLine()));
                    $this->processes[$pidFile]->stop();
                    unset($this->processes[$pidFile]);
                } elseif (!$this->processes[$pidFile]->isRunning()) {
                    $exitCode = $this->processes[$pidFile]->getExitCode();
                    if ($exitCode === 143 || $exitCode === 147) {
                        $log->writeln(sprintf('Process killed: %s', $this->processes[$pidFile]->getCommandLine()));
                    } elseif ($exitCode > 0) {
                        $log->writeln(sprintf('Process stopped unexpectedly with exit code %s: %s', $exitCode, $this->processes[$pidFile]->getCommandLine()));
                    }
                    unlink($pidFile);
                    unset($this->processes[$pidFile]);
                }
            }
        }
    }
}
