<?php

declare(strict_types=1);

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
    private readonly OutputInterface $output;
    private ?string $message = null;
    private bool $visible = false;

    /**
     * @param OutputInterface $output
     */
    public function __construct(OutputInterface $output)
    {
        $this->output = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /**
     * Shows a progress message, if the output supports decoration (ASCII escape codes).
     */
    public function showIfOutputDecorated(string $message): void
    {
        if ($this->output->isDecorated()) {
            $this->show($message);
        }
    }

    /**
     * Shows a progress message.
     */
    public function show(string $message): void
    {
        if ($message === '' || $message === $this->message) {
            return;
        }
        $message = rtrim($message, "\n") . "\n";
        $this->output->write($message);
        $this->message = $message;
        $this->visible = true;
    }

    /**
     * Hides the progress message, if one is visible. Mark it as done if hiding is not supported.
     */
    public function done(): void
    {
        if ($this->visible) {
            if ($this->output->isDecorated() && !$this->output->isVeryVerbose()) {
                $this->overwrite('', \substr_count((string) $this->message, "\n"));
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
    private function overwrite(string $message, int $lineCount = 0): void
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
