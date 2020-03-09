<?php

namespace Platformsh\Cli\Exception;

use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

/**
 * An exception thrown when a process fails.
 *
 * This is a copy of Symfony's ProcessFailedException, with an improved error
 * message.
 *
 * @see \Symfony\Component\Process\Exception\ProcessFailedException
 */
class ProcessFailedException extends RuntimeException
{
    private $process;

    /**
     * ProcessFailedException constructor.
     *
     * @param \Symfony\Component\Process\Process $process
     *     The failed process.
     * @param bool $includeOutput
     *     Whether to include the output in the exception message. Set to false
     *     if the output would have already been displayed.
     */
    public function __construct(Process $process, $includeOutput)
    {
        if ($process->isSuccessful()) {
            throw new \InvalidArgumentException('Expected a failed process, but the given process was successful.');
        }

        $message = 'The command failed with the exit code: ' . $process->getExitCode();
        $message .= "\n\nFull command: " . $process->getCommandLine();
        if ($includeOutput) {
            $message .= "\n\nOutput:\n" . $process->getOutput();
            $message .= "\n\nError output:\n" . $process->getErrorOutput();
        }

        parent::__construct($message);

        $this->process = $process;
    }

    /**
     * @return \Symfony\Component\Process\Process
     */
    public function getProcess()
    {
        return $this->process;
    }
}
