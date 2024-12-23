<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

readonly class Io
{
    private OutputInterface $stdErr;

    public function __construct(OutputInterface $output, private ?InputInterface $input = null)
    {
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    public function debug(string $message): void
    {
        if ($this->stdErr->isDebug()) {
            $this->labeledMessage('DEBUG', $message);
        }
    }

    /**
     * Print a message with a label.
     *
     * @param string $label
     * @param string $message
     * @param int $options
     */
    private function labeledMessage(string $label, string $message, int $options = 0): void
    {
        $this->stdErr->writeln('<options=reverse>' . strtoupper($label) . '</> ' . $message, $options);
    }

    /**
     * Print a warning about deprecated option(s).
     *
     * @param string[]    $options  A list of option names (without "--").
     * @param string|null $template The warning message template. "%s" is
     *                              replaced by the option name.
     */
    public function warnAboutDeprecatedOptions(array $options, ?string $template = null): void
    {
        if (!isset($this->input)) {
            return;
        }
        if ($template === null) {
            $template = 'The option --%s is deprecated and no longer used. It will be removed in a future version.';
        }
        foreach ($options as $option) {
            if ($this->input->hasOption($option) && $this->input->getOption($option)) {
                $this->labeledMessage(
                    'DEPRECATED',
                    sprintf($template, $option),
                );
            }
        }
    }

    /**
     * Checks if running in a terminal.
     *
     * @param resource|int $descriptor
     *
     * @return bool
     *
     * @noinspection PhpMissingParamTypeInspection
     */
    public function isTerminal($descriptor): bool
    {
        return !function_exists('posix_isatty') || posix_isatty($descriptor);
    }
}
