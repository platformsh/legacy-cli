<?php

namespace Platformsh\Cli\Util;

use Platformsh\Cli\Helper\ShellHelper;
use Platformsh\Cli\Helper\ShellHelperInterface;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UrlUtil
{
    protected $shellHelper;

    public function __construct(ShellHelperInterface $shellHelper = null)
    {
        $this->shellHelper = $shellHelper ?: new ShellHelper();
    }

    /**
     * @param InputDefinition $inputDefinition
     */
    public function addBrowserOption(InputDefinition $inputDefinition)
    {
        $inputDefinition->addOption(new InputOption(
            'browser',
             null,
            InputOption::VALUE_REQUIRED,
            'The browser to use to open the URL. Set 0 for none.'
        ));
    }

    /**
     * @param InputDefinition $inputDefinition
     */
    public function addPipeOption(InputDefinition $inputDefinition)
    {
        $inputDefinition->addOption(new InputOption(
          'pipe',
          null,
          InputOption::VALUE_NONE,
          'Output the raw URL, suitable for piping to another command.'
        ));
    }

    /**
     * Open a URL in the browser, or print it.
     *
     * @param string          $url
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @param bool $quiet
     */
    public function openUrl($url, InputInterface $input, OutputInterface $output, $quiet = false)
    {
        $stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $browser = $input->hasOption('browser') ? $input->getOption('browser') : null;

        if ($input->hasOption('pipe') && $input->getOption('pipe')) {
            $output->writeln($url);
            return;
        }

        if (!getenv('DISPLAY') && strpos(PHP_OS, 'WIN') === false) {
            if ($browser !== '0' && $output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
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
        } elseif (!$this->shellHelper->commandExists($browser)) {
            // The user has specified a browser, but it can't be found.
            $stdErr->writeln("<error>Browser not found: $browser</error>");
            $browser = false;
        }

        if ($browser) {
            $opened = $this->shellHelper->execute(array($browser, $url));
            if ($opened) {
                $stdErr->writeln("Opened: $url");

                return;
            }
        }

        if (!$quiet) {
            $output->writeln($url);
        }
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
            if ($this->shellHelper->commandExists($browser)) {
                return $browser;
            }
        }

        return false;
    }
}
