<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class Shell
{

    /** @var OutputInterface */
    protected $output;

    /** @var OutputInterface */
    protected $stdErr;

    public function __construct(OutputInterface $output = null)
    {
        $this->setOutput($output ?: new NullOutput());
    }

    /**
     * Change the output object.
     *
     * @param OutputInterface $output
     */
    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
        $this->stdErr = $output instanceof ConsoleOutputInterface
            ? $output->getErrorOutput()
            : $output;
    }

    /**
     * Execute a command, using STDIN, STDERR and STDOUT directly.
     *
     * @param string      $commandline
     * @param string|null $dir
     * @param array       $env
     *
     * @return int
     *   The command's exit code (0 on success, a different integer on failure).
     */
    public function executeSimple($commandline, $dir = null, array $env = [])
    {
        $this->stdErr->writeln(
            'Running command: <info>' . $commandline . '</info>',
            OutputInterface::VERBOSITY_VERBOSE
        );

        if (!empty($env)) {
            $this->showEnvMessage($env);
            $env = $env + $this->getParentEnv();
        } else {
            $env = null;
        }

        $this->showWorkingDirMessage($dir);

        $process = proc_open($commandline, [STDIN, STDOUT, STDERR], $pipes, $dir, $env);

        return proc_close($process);
    }

    /**
     * Execute a command.
     *
     * @param string|array $args
     * @param string|null  $dir
     * @param bool         $mustRun
     * @param bool         $quiet
     * @param array        $env
     * @param int|null     $timeout
     * @param string|null  $input
     *
     * @throws \Symfony\Component\Process\Exception\RuntimeException
     *   If $mustRun is enabled and the command fails.
     *
     * @return bool|string
     *   False if the command fails, true if it succeeds with no output, or a
     *   string if it succeeds with output.
     */
    public function execute($args, $dir = null, $mustRun = false, $quiet = true, array $env = [], $timeout = 3600, $input = null)
    {
        $process = new Process($args, null, null, $input, $timeout);

        // Avoid adding 'exec' to every command. It is not needed in this
        // context as we do not need to send signals to the process. Also it
        // causes compatibility issues, at least with the shell built-in command
        // 'command' on  Travis containers.
        // See https://github.com/symfony/symfony/issues/23495
        $process->setCommandLine($process->getCommandLine());

        if ($timeout === null) {
            set_time_limit(0);
        }

        $this->stdErr->writeln(
            "Running command: <info>" . $process->getCommandLine() . "</info>",
            OutputInterface::VERBOSITY_VERBOSE
        );

        $blankLine = false;

        if (!empty($input) && is_string($input)) {
            $this->stdErr->writeln(sprintf('  Command input: <info>%s</info>', $input), OutputInterface::VERBOSITY_VERBOSE);
            $blankLine = true;
        }

        if (!empty($env)) {
            $this->showEnvMessage($env);
            $blankLine = true;
            $process->setEnv($env + $this->getParentEnv());
        }

        if ($dir && is_dir($dir)) {
            $process->setWorkingDirectory($dir);
            $this->showWorkingDirMessage($dir);
            $blankLine = true;
        }

        // Conditional blank line just to aid debugging.
        if ($blankLine) {
            $this->stdErr->writeln('', OutputInterface::VERBOSITY_VERBOSE);
        }

        $result = $this->runProcess($process, $mustRun, $quiet);

        // Another blank line after the command output ends.
        $this->stdErr->writeln('', OutputInterface::VERBOSITY_VERBOSE);

        return is_int($result) ? $result === 0 : $result;
    }

    /**
     * @param string|null $dir
     */
    private function showWorkingDirMessage($dir)
    {
        if ($dir !== null) {
            $this->stdErr->writeln('  Working directory: ' . $dir, OutputInterface::VERBOSITY_VERY_VERBOSE);
        }
    }

    /**
     * @param array $env
     */
    private function showEnvMessage(array $env)
    {
        if (!empty($env)) {
            $message = ['  Using additional environment variables:'];
            foreach ($env as $variable => $value) {
                $message[] = sprintf('    <info>%s</info>=%s', $variable, $value);
            }
            $this->stdErr->writeln($message, OutputInterface::VERBOSITY_VERY_VERBOSE);
        }
    }

    /**
     * Attempt to read useful environment variables from the parent process.
     *
     * @return array
     */
    protected function getParentEnv()
    {
        if (PHP_VERSION_ID >= 70100) {
            return getenv();
        }
        // In PHP <7.1 there isn't a way to read all of the current environment
        // variables. If PHP is running with a variables_order that includes
        // 'e', then $_ENV should be populated.
        if (!empty($_ENV) && stripos(ini_get('variables_order'), 'e') !== false) {
            return $_ENV;
        }

        // If $_ENV is empty, then guess all the variables that we might want to use.
        $candidates = [
            'TERM',
            'TERM_SESSION_ID',
            'TMPDIR',
            'SSH_AGENT_PID',
            'SSH_AUTH_SOCK',
            'PATH',
            'LANG',
            'LC_ALL',
            'LC_CTYPE',
            'PAGER',
            'LESS',
        ];
        $variables = [];
        foreach ($candidates as $name) {
            $variables[$name] = getenv($name);
        }

        return array_filter($variables, function ($value) {
            return $value !== false;
        });
    }

    /**
     * Run a process.
     *
     * @param Process $process
     * @param bool    $mustRun
     * @param bool    $quiet
     *
     * @throws \Symfony\Component\Process\Exception\RuntimeException
     *   If the process fails or times out, and $mustRun is true.
     *
     * @return int|string
     *   The exit code of the process if it fails, true if it succeeds with no
     *   output, or a string if it succeeds with output.
     */
    protected function runProcess(Process $process, $mustRun = false, $quiet = true)
    {
        try {
            $process->mustRun(function ($type, $buffer) use ($quiet) {
                $output = $type === Process::ERR ? $this->output : $this->stdErr;
                if ($quiet) {
                    // Always show stderr output in debug mode.
                    if ($type !== Process::ERR || !$output->isDebug()) {
                        return;
                    }
                }
                $output->write(preg_replace('/^/m', '  ', $buffer));
            });
        } catch (ProcessFailedException $e) {
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
     * @return string|bool
     *   A list of command paths (one per line) or false on failure.
     */
    protected function findWhere($command, $noticeOnError = true)
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
                foreach ($commands as $key => $args) {
                    try {
                        $result[$command] = $this->execute($args);
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
    public function exceptionMeansCommandDoesNotExist(ProcessFailedException $e) {
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
    public function commandExists($command)
    {
        return (bool) $this->findWhere($command, false);
    }

    /**
     * Find the absolute path to an executable.
     *
     * @param string $command
     *
     * @return string
     */
    public function resolveCommand($command)
    {
        if ($fullPaths = $this->findWhere($command)) {
            $fullPaths = preg_split('/[\r\n]/', trim($fullPaths));
            $command = end($fullPaths);
        }

        return $command;
    }
}
