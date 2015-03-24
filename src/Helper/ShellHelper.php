<?php

namespace Platformsh\Cli\Helper;

use Platformsh\Cli\Util\HasLoggerTrait;
use Platformsh\Cli\Util\LoggerAwareInterface;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\ProcessBuilder;

class ShellHelper extends Helper implements ShellHelperInterface, LoggerAwareInterface
{

    use HasLoggerTrait;

    public function getName()
    {
        return 'shell';
    }

    /**
     * Log output messages.
     *
     * @param mixed  $type
     * @param string $buffer
     */
    public function shellLog($type, $buffer)
    {
        $indent = '  ';
        $this->getLogger()->info($indent . str_replace("\n", "\n$indent", trim($buffer)));
    }

    /**
     * @inheritdoc
     *
     * @throws \Exception
     *   If $mustRun is enabled and the command fails.
     */
    public function execute(array $args, $dir = null, $mustRun = false, $quiet = true)
    {
        $builder = new ProcessBuilder($args);
        $process = $builder->getProcess();

        // The default timeout is 1 minute. Increase it to 1 hour.
        $process->setTimeout(3600);

        if ($dir) {
            $process->setWorkingDirectory($dir);
        }

        $this->getLogger()->debug("Running command: <info>" . $process->getCommandLine() . "</info>");

        try {
            $process->mustRun($quiet ? null : array($this, 'shellLog'));
        } catch (ProcessFailedException $e) {
            if (!$mustRun) {
                return false;
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
     * @inheritdoc
     */
    public function commandExists($command)
    {
        $args = array('command', '-v', $command);
        if (strpos(PHP_OS, 'WIN') !== false) {
            $args = array('where', $command);
        }

        return (bool) $this->execute($args);
    }
}
