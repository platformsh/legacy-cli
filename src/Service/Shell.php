<?php

namespace Platformsh\Cli\Service;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

class Shell
{

    /** @var OutputInterface */
    protected $output;

    protected $defaultTimeout = 3600;

    public function __construct(OutputInterface $output = null)
    {
        $this->output = $output ?: new NullOutput();
    }

    /**
     * Change the output object.
     *
     * @param OutputInterface $output
     */
    public function setOutput(OutputInterface $output)
    {
        if ($output instanceof ConsoleOutputInterface) {
            $output = $output->getErrorOutput();
        }
        $this->output = $output;
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
        $this->output->writeln('Running command: <info>' . $commandline . '</info>', OutputInterface::VERBOSITY_VERBOSE);

        $env = $env ? $env + $this->getParentEnv() : null;
        $process = proc_open($commandline, [STDIN, STDOUT, STDERR], $pipes, $dir, $env);

        return proc_close($process);
    }

    /**
     * Execute a command.
     *
     * @param array       $args
     * @param string|null $dir
     * @param bool        $mustRun
     * @param bool        $quiet
     * @param array       $env
     *
     * @throws \Exception
     *   If $mustRun is enabled and the command fails.
     *
     * @return bool|string
     *   False if the command fails, true if it succeeds with no output, or a
     *   string if it succeeds with output.
     */
    public function execute(array $args, $dir = null, $mustRun = false, $quiet = true, array $env = [])
    {
        $builder = new ProcessBuilder($args);
        $process = $builder->getProcess();
        if (!empty($env)) {
            $process->setEnv($env + $this->getParentEnv());
        }
        $process->setTimeout($this->defaultTimeout);
        if ($dir) {
            $process->setWorkingDirectory($dir);
        }

        $result = $this->runProcess($process, $mustRun, $quiet);

        return is_int($result) ? $result === 0 : $result;
    }

    /**
     * Attempt to read useful environment variables from the parent process.
     *
     * We can't rely on the PHP having a variables_order that includes 'e', so
     * $_ENV may be empty.
     *
     * @return array
     */
    protected function getParentEnv()
    {
        if (!empty($_ENV)) {
            return $_ENV;
        }

        $candidates = [
            'TERM',
            'TERM_SESSION_ID',
            'TMPDIR',
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

        return array_filter($variables);
    }

    /**
     * Run a process.
     *
     * @param Process     $process
     * @param bool        $mustRun
     * @param bool        $quiet
     *
     * @return int|string
     *   The exit code of the process if it fails, true if it succeeds with no
     *   output, or a string if it succeeds with output.
     *
     * @throws \Exception
     */
    protected function runProcess(Process $process, $mustRun = false, $quiet = true)
    {
        $this->output->writeln("Running command: <info>" . $process->getCommandLine() . "</info>", OutputInterface::VERBOSITY_VERBOSE);

        try {
            $process->mustRun($quiet ? null : function ($type, $buffer) {
                $this->output->write(preg_replace('/^/m', '  ', $buffer));
            });
        } catch (ProcessFailedException $e) {
            if (!$mustRun) {
                return $process->getExitCode();
            }
            // The default for ProcessFailedException is to print the entire
            // STDOUT and STDERR. But if $quiet is disabled, then the user will
            // have already seen the command's output.  So we need to re-throw
            // the exception with a much shorter message.
            $message = "The command failed with the exit code: " . $process->getExitCode();
            $message .= "\n\nFull command: " . $process->getCommandLine();
            if ($quiet) {
                $message .= "\n\nError output:\n" . $process->getErrorOutput();
            }
            throw new \Exception($message);
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
            }
            else {
                $args = ['command', '-v', $command];
                if (strpos(PHP_OS, 'WIN') !== false) {
                    $args = ['where', $command];
                }
                $result[$command] = $this->execute($args, null, false, true);
                if ($result[$command] === false && $noticeOnError) {
                    trigger_error(sprintf("Failed to find command via: %s", implode(' ', $args)), E_USER_NOTICE);
                }
            }
        }

        return $result[$command];
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
