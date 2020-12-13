<?php

namespace Platformsh\Cli\Console;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shows and optionally hides a message.
 *
 * Use Symfony's ProgressBar for anything more complicated.
 */
class ProgressMessage
{
    private $output;
    private $message;
    private $visible = false;

    /**
     * @param OutputInterface $output
     */
    public function __construct(OutputInterface $output)
    {
        $this->output = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /**
     * Shows a progress message, if the output supports decoration (ASCII escape codes).
     *
     * @param string $message
     */
    public function showIfOutputDecorated($message)
    {
        if ($this->output->isDecorated()) {
            $this->show($message);
        }
    }

    /**
     * Shows a progress message.
     *
     * @param string $message
     */
    public function show($message)
    {
        if ($message === '' || $message === $this->message) {
            return;
        }
        $this->output->write($message);
        $this->message = $message;
        $this->visible = true;
    }

    /**
     * Hides the progress message, if one is visible. Mark it as done if hiding is not supported.
     */
    public function done()
    {
        if ($this->visible) {
            if ($this->output->isDecorated()) {
                $this->overwrite('', \substr_count($this->message, "\n"));
            } else {
                $this->output->writeln('');
            }
            $this->visible = false;
        }
    }

    /**
     * Overwrites a previous message to the output.
     *
     * Borrowed from Symfony's ProgressBar.
     *
     * @param string $message
     * @param int    $lineCount
     */
    private function overwrite($message, $lineCount = 0)
    {
        // Erase $lineCount previous lines.
        if ($lineCount > 0) {
            $message = str_repeat("\x1B[1A\x1B[2K", $lineCount) . $message;
        }

        // Move the cursor to the beginning of the line and erase the line.
        $message = "\x0D\x1B[2K$message";

        $this->output->write($message);
    }
}
