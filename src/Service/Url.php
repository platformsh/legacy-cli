<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Url implements InputConfiguringInterface
{
    protected OutputInterface $stdErr;

    public function __construct(protected Shell $shell, protected InputInterface $input, protected OutputInterface $output)
    {
        $this->stdErr = $this->output instanceof ConsoleOutputInterface
            ? $this->output->getErrorOutput()
            : $this->output;
    }

    /**
     * @param InputDefinition $definition
     */
    public static function configureInput(InputDefinition $definition): void
    {
        $definition->addOption(new InputOption(
            'browser',
            null,
            InputOption::VALUE_REQUIRED,
            'The browser to use to open the URL. Set 0 for none.',
        ));
        $definition->addOption(new InputOption(
            'pipe',
            null,
            InputOption::VALUE_NONE,
            'Output the URL to stdout.',
        ));
    }

    /**
     * Test whether URL(s) can be opened in the default browser.
     *
     * @return bool
     */
    public function canOpenUrls(): bool
    {
        return $this->hasDisplay()
            && $this->getBrowser($this->input->hasOption('browser') ? $this->input->getOption('browser') : null) !== false;
    }

    /**
     * Opens a URL in the browser, or prints it.
     *
     * @return bool True if a browser was used, false otherwise.
     */
    public function openUrl(string $url, bool $print = true): bool
    {
        $browserOption = $this->input->hasOption('browser') ? $this->input->getOption('browser') : null;
        $open = true;
        $success = false;

        // If the user wants to pipe the output to another command, stop here.
        if ($this->input->hasOption('pipe') && $this->input->getOption('pipe')) {
            $open = false;
            $print = true;
        }
        // Check if the user has requested not to use a browser.
        elseif ($browserOption === '0') {
            $open = false;
        }
        // Check for a display (if not on Windows or OS X).
        elseif (!$this->hasDisplay()) {
            $open = false;
            $this->stdErr->writeln('Not opening URL (no display found)', OutputInterface::VERBOSITY_VERBOSE);
        }

        // Open the URL.
        if ($open && ($browser = $this->getBrowser($browserOption))) {
            if (OsUtil::isWindows() && $browser === 'start') {
                // The start command needs an extra (title) argument.
                $args = [$browser, '', $url];
            } else {
                $args  = [$browser, $url];
            }

            $success = $this->shell->execute($args) !== false;
        }

        // Print the URL.
        if ($print) {
            $this->output->writeln($url);
        }

        return $success;
    }

    /**
     * Check for a display (if not on Windows or OS X).
     *
     * @return bool
     */
    public function hasDisplay(): bool
    {
        if (getenv('DISPLAY')) {
            return getenv('DISPLAY') !== 'none';
        }

        return OsUtil::isWindows() || OsUtil::isOsX();
    }

    /**
     * Finds the browser to use.
     *
     * @param string|null $browserOption
     *
     * @return string|false A browser command, or false if no browser can or
     *                      should be used.
     */
    private function getBrowser(?string $browserOption = null): string|false
    {
        if ($browserOption === '0') {
            return false;
        } elseif (!empty($browserOption)) {
            [$command, ] = explode(' ', $browserOption, 2);
            if (!$this->shell->commandExists($command)) {
                $this->stdErr->writeln(sprintf('Command not found: <error>%s</error>', $command));
                return false;
            }

            return $browserOption;
        }

        return $this->getDefaultBrowser();
    }

    /**
     * Find a default browser to use.
     *
     * @return string|false
     */
    private function getDefaultBrowser(): string|false
    {
        if (OsUtil::isWindows()) {
            return 'start';
        }
        if (OsUtil::isOsX()) {
            return 'open';
        }
        $browsers = ['xdg-open', 'gnome-open'];
        foreach ($browsers as $browser) {
            if ($this->shell->commandExists($browser)) {
                return $browser;
            }
        }

        return false;
    }
}
