<?php

namespace Platformsh\Cli\Service;

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

    public function __construct(Shell $shell, InputInterface $input, OutputInterface $output)
    {
        $this->shell = $shell;
        $this->input = $input;
        $this->output = $output;
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
        $stdErr = $this->output instanceof ConsoleOutputInterface
            ? $this->output->getErrorOutput()
            : $this->output;
        $browser = $this->input->hasOption('browser') ? $this->input->getOption('browser') : null;

        if ($this->input->hasOption('pipe') && $this->input->getOption('pipe')) {
            $this->output->writeln($url);
            return;
        }

        if (!getenv('DISPLAY') && strpos(PHP_OS, 'WIN') === false) {
            if ($browser !== '0' && $this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $stdErr->writeln(sprintf('Not opening URL %s (no display found)', $url));
            }
            return;
        }

        if ($browser === '0') {
            // The user has requested not to use a browser.
            $browser = false;
        } elseif (empty($browser)) {
            // Find a default browser to use.
            $browser = $this->getDefaultBrowser();
        } elseif (!$this->shell->commandExists($browser)) {
            // The user has specified a browser, but it can't be found.
            $stdErr->writeln("<error>Browser not found: $browser</error>");
            $browser = false;
        }

        if ($browser) {
            $opened = $this->shell->execute(array($browser, $url));
            if ($opened) {
                $stdErr->writeln("Opened: $url");

                return;
            }
        }

        $this->output->writeln($url);
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
