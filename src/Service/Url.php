<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Url implements InputConfiguringInterface
{
    protected $input;
    protected $shell;
    protected $output;
    protected $stdErr;

    public function __construct(Shell $shell, InputInterface $input, OutputInterface $output)
    {
        $this->shell = $shell;
        $this->input = $input;
        $this->output = $output;
        $this->stdErr = $this->output instanceof ConsoleOutputInterface
            ? $this->output->getErrorOutput()
            : $this->output;
    }

    /**
     * @param \Symfony\Component\Console\Input\InputDefinition $definition
     */
    public static function configureInput(InputDefinition $definition)
    {
        $definition->addOption(new InputOption(
            'browser',
            null,
            InputOption::VALUE_REQUIRED,
            'The browser to use to open the URL. Set 0 for none.'
        ));
        $definition->addOption(new InputOption(
            'pipe',
            null,
            InputOption::VALUE_NONE,
            'Output the URL to stdout.'
        ));
    }

    /**
     * Open a URL in the browser, or print it.
     *
     * @param string $url
     */
    public function openUrl($url)
    {
        if ($browser = $this->getBrowser()) {
            $this->shell->executeSimple($browser . ' ' . escapeshellarg($url));
        }

        $this->output->writeln($url);
    }

    /**
     * Finds the browser to use.
     *
     * @return string|false A browser command, or false if no browser can or
     *                      should be used.
     */
    protected function getBrowser()
    {
        $browser = $this->input->hasOption('browser') ? $this->input->getOption('browser') : null;

        // If the user wants to pipe the output to another command, stop here.
        if ($this->input->hasOption('pipe') && $this->input->getOption('pipe')) {
            return false;
        }

        // Check if the user has requested not to use a browser.
        if ($browser === '0') {
            return false;
        }

        // Check for a display (if not on Windows or OS X).
        if (!getenv('DISPLAY') && !OsUtil::isWindows() && !OsUtil::isOsX()) {
            $this->stdErr->writeln('Not opening URL (no display found)', OutputInterface::VERBOSITY_VERBOSE);
            return false;
        }

        if (!empty($browser)) {
            list($command, ) = explode(' ', $browser, 2);
            if (!$this->shell->commandExists($command)) {
                $this->stdErr->writeln(sprintf('Command not found: <error>%s</error>', $command));
                return false;
            }

            return $browser;
        }

        return $this->getDefaultBrowser();
    }

    /**
     * Find a default browser to use.
     *
     * @return string|false
     */
    protected function getDefaultBrowser()
    {
        $potential = array('xdg-open', 'open', 'start');
        foreach ($potential as $browser) {
            if ($this->shell->commandExists($browser)) {
                return $browser;
            }
        }

        return false;
    }
}
