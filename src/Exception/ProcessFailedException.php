<?php

declare(strict_types=1);

namespace Platformsh\Cli\Exception;

use Symfony\Component\Process\Process;

/**
 * An exception thrown when a process fails.
 *
 * This extends Symfony's ProcessFailedException, with an improved error message.
 */
class ProcessFailedException extends \Symfony\Component\Process\Exception\ProcessFailedException
{
    /**
     * @param Process $process
     *     The failed process.
     * @param bool $includeOutput
     *     Whether to include the output in the exception message. Set to false
     *     if the output would have already been displayed.
     */
    public function __construct(Process $process, $includeOutput)
    {
        parent::__construct($process);

        $message = 'The command failed with the exit code: ' . $process->getExitCode();
        $message .= "\n\nFull command: " . $process->getCommandLine();
        if ($includeOutput) {
            $message .= "\n\nOutput:\n" . $process->getOutput();
            $message .= "\n\nError output:\n" . $process->getErrorOutput();
        }

        $this->message = $message;
    }
}
