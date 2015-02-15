<?php

namespace CommerceGuys\Platform\Cli\Helper;

use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Process\Exception\ProcessFailedException;

class ShellHelper extends Helper implements ShellHelperInterface {

    /** @var OutputInterface|null */
    protected $output;

    public function getName()
    {
        return 'shell';
    }

    public function __construct(OutputInterface $output = null)
    {
        $this->output = $output;
    }

    public function setOutput(OutputInterface $output = null)
    {
        $this->output = $output;
    }

    /**
     * Log output messages.
     *
     * @param mixed $type
     * @param string $buffer
     *
     * @todo in theory this could use the ConsoleLogger, but the formatting is ugly and impossible to override
     */
    public function log($type, $buffer)
    {
        if ($this->output) {
            $this->output->write($buffer);
        }
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
        try {
            $process->mustRun($quiet ? null : array($this, 'log'));
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
