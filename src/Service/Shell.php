<?php

namespace Platformsh\Cli\Service;

use Symfony\Component\Process\Exception\RuntimeException;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class Shell
{
    protected OutputInterface $output;
    protected OutputInterface $stdErr;

    private string $debugPrefix = '<options=reverse>#</> ';

    private static ?string $phpVersion = null;

    public function __construct(?OutputInterface $output = null)
    {
        $this->setOutput($output ?: new NullOutput());
    }

    /**
     * Change the output object.
     *
     * @param OutputInterface $output
     */
    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
        $this->stdErr = $output instanceof ConsoleOutputInterface
            ? $output->getErrorOutput()
            : $output;
    }

    /**
     * Executes a command, using STDIN, STDERR and STDOUT directly.
     *
     * @return int
     *   The command's exit code (0 on success, a different integer on failure).
     */
    public function executeSimple(string $commandline, ?string $dir = null, array $env = []): int
    {
        $this->stdErr->writeln(
            sprintf( '%sRunning command: <info>%s</info>', $this->debugPrefix, $commandline),
            OutputInterface::VERBOSITY_VERY_VERBOSE
        );

        if (!empty($env)) {
            $this->showEnvMessage($env);
            $env = $env + getenv();
        } else {
            $env = null;
        }

        $this->showWorkingDirMessage($dir);

        $process = proc_open($commandline, [STDIN, STDOUT, STDERR], $pipes, $dir, $env);

        return proc_close($process);
    }

    /**
     * Executes a command.
     *
     * @param array|string $args
     * @param string|null $dir
     * @param bool         $mustRun
     * @param bool         $quiet
     * @param array        $env
     * @param int|null     $timeout
     * @param string|null  $input
     *
     * @return bool|string
     *   False if the command fails, true if it succeeds with no output, or a
     *   string if it succeeds with output.
     *@throws RuntimeException
     *   If $mustRun is enabled and the command fails.
     *
     */
    public function execute(array|string $args, ?string $dir = null, bool $mustRun = false, bool $quiet = true, array $env = [], ?int $timeout = 3600, mixed $input = null): bool|string
    {
        $process = $this->setupProcess($args, $dir, $env, $timeout, $input);
        $result = $this->runProcess($process, $mustRun, $quiet);

        return is_int($result) ? $result === 0 : $result;
    }

    /**
     * Executes a command and returns the process object.
     */
    public function executeCaptureProcess(string|array $args, ?string $dir = null, bool $mustRun = false, bool $quiet = true, array $env = [], ?int $timeout = 3600, mixed $input = null): Process
    {
        $process = $this->setupProcess($args, $dir, $env, $timeout, $input);
        $this->runProcess($process, $mustRun, $quiet);
        return $process;
    }

    /**
     * Sets up a Process and reports to the user that the command is being run.
     */
    private function setupProcess(string|array $args, ?string $dir = null, array $env = [], int|null $timeout = 3600, mixed $input = null): Process
    {
        if (is_string($args)) {
            $process = Process::fromShellCommandline($args, null, null, $input, $timeout);
        } else {
            $process = new Process($args, null, null, $input, $timeout);
        }

        if ($timeout === null) {
            set_time_limit(0);
        }

        $this->stdErr->writeln(
            sprintf( '%sRunning command: <info>%s</info>', $this->debugPrefix, $process->getCommandLine()),
            OutputInterface::VERBOSITY_VERY_VERBOSE
        );

        if (!empty($input) && is_string($input) && $this->stdErr->isDebug()) {
            $this->stdErr->writeln(sprintf('%s  Command input: <info>%s</info>', $this->debugPrefix, $input));
        }

        if (!empty($env)) {
            $this->showEnvMessage($env);
            $process->setEnv($env + getenv());
        }

        if ($dir && is_dir($dir)) {
            $process->setWorkingDirectory($dir);
            $this->showWorkingDirMessage($dir);
        }

        return $process;
    }

    private function showWorkingDirMessage(?string $dir): void
    {
        if ($dir !== null && $this->stdErr->isDebug()) {
            $this->stdErr->writeln($this->debugPrefix . '  Working directory: ' . $dir);
        }
    }

    /**
     * @param array $env
     */
    private function showEnvMessage(array $env): void
    {
        if (!empty($env) && $this->stdErr->isDebug()) {
            $message = [$this->debugPrefix . '  Using additional environment variables:'];
            foreach ($env as $variable => $value) {
                $message[] = sprintf('%s    <info>%s</info>=%s', $this->debugPrefix, $variable, $value);
            }
            $this->stdErr->writeln($message);
        }
    }

    /**
     * Run a process.
     *
     * @param Process $process
     * @param bool    $mustRun
     * @param bool    $quiet
     *
     * @throws RuntimeException
     *   If the process fails or times out, and $mustRun is true.
     *
     * @return int|bool|string
     *   The exit code of the process if it fails, true if it succeeds with no
     *   output, or a string if it succeeds with output.
     */
    protected function runProcess(Process $process, bool $mustRun = false, bool $quiet = true): int|bool|string
    {
        try {
            $process->mustRun(function ($type, $buffer) use ($quiet): void {
                $output = $type === Process::ERR ? $this->stdErr : $this->output;
                // Show the output if $quiet is false, and always show stderr
                // output in debug mode.
                if (!$quiet || ($type === Process::ERR && $output->isDebug())) {
                    // Indent the output by 2 spaces.
                    $output->write(preg_replace('/(^|[\n\r]+)(.)/', '$1  $2', $buffer));
                }
            });
        } catch (ProcessFailedException) {
            if (!$mustRun) {
                return $process->getExitCode();
            }
            // The default for Symfony's ProcessFailedException is to print the
            // entire STDOUT and STDERR. But if $quiet is disabled, then the user
            // will have already seen the command's output.  So we need to
            // re-throw the exception with our own ProcessFailedException, which
            // will generate a much shorter message.
            throw new \Platformsh\Cli\Exception\ProcessFailedException($process, $quiet);
        }
        $output = $process->getOutput();

        return $output ? rtrim($output) : true;
    }

    /**
     * Run 'where' or equivalent on a command.
     *
     * @param string $command
     * @param bool $noticeOnError
     *
     * @return string|false
     *   A list of command paths (one per line) or false on failure.
     */
    protected function findWhere(string $command, bool $noticeOnError = true): string|false
    {
        static $result;
        if (!isset($result[$command])) {
            if (is_executable($command)) {
                $result[$command] = $command;
            } else {
                if (OsUtil::isWindows()) {
                    $commands = [['where', $command], ['which', $command]];
                } else {
                    $commands = [['command', '-v', $command], ['which', $command]];
                }
                foreach ($commands as $args) {
                    try {
                        $result[$command] = $this->execute($args, null, true);
                    } catch (ProcessFailedException $e) {
                        $result[$command] = false;
                        if ($this->exceptionMeansCommandDoesNotExist($e)) {
                            continue;
                        }
                    }
                    break;
                }
                if ($result[$command] === false && $noticeOnError) {
                    trigger_error(sprintf("Failed to find command via: %s", implode(' ', $args)), E_USER_NOTICE);
                }
            }
        }

        return $result[$command];
    }

    /**
     * Tests a process exception to see if it means the command does not exist.
     *
     * @param ProcessFailedException $e
     *
     * @return bool
     */
    public function exceptionMeansCommandDoesNotExist(ProcessFailedException $e): bool {
        $process = $e->getProcess();
        if ($process->getExitCode() === 127) {
            return true;
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            if ($process->isOutputDisabled()) {
                return true;
            }
            if (\stripos($process->getErrorOutput(), 'is not recognized as an internal or external command')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Test whether a CLI command exists.
     *
     * @param string $command
     *
     * @return bool
     */
    public function commandExists(string $command): bool
    {
        return (bool) $this->findWhere($command, false);
    }

    /**
     * Finds the absolute path to an executable.
     */
    public function resolveCommand(string $command): string
    {
        if ($fullPaths = $this->findWhere($command)) {
            $fullPaths = preg_split('/[\r\n]/', trim($fullPaths));
            $command = end($fullPaths);
        }

        return $command;
    }

    /**
     * Returns the locally installed version of PHP.
     *
     * Falls back to the version of PHP running the CLI (which may or may not
     * be the same).
     */
    public function getPhpVersion(): string
    {
        if (!isset(self::$phpVersion)) {
            $result = $this->execute([
                (new PhpExecutableFinder())->find() ?: PHP_BINARY,
                '-r',
                'echo PHP_VERSION;',
            ]);
            self::$phpVersion = is_string($result) ? $result : PHP_VERSION;
        }
        return self::$phpVersion;
    }
}
