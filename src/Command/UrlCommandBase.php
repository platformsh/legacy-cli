<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

abstract class UrlCommandBase extends PlatformCommand
{

    protected function configure()
    {
        $this->addOption(
            'browser',
            null,
            InputOption::VALUE_OPTIONAL,
            'The browser to use to open the URL. Set 0 for none.'
          )
          ->addOption(
            'pipe',
            null,
            InputOption::VALUE_NONE,
            'Output the raw URL, suitable for piping to another command.'
          );
    }

    /**
     * Open a URL in the browser, or print it.
     *
     * @param string $url
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    protected function openUrl($url, InputInterface $input, OutputInterface $output)
    {
        $browser = $input->getOption('browser');

        if ($input->getOption('pipe') || !$this->isTerminal($output)) {
            $output->write($url);
            return;
        }

        if ($browser === '0') {
            // The user has requested not to use a browser.
            $browser = false;
        }
        elseif (empty($browser)) {
            // Find a default browser to use.
            $browser = $this->getDefaultBrowser();
        }
        elseif (!$this->browserExists($browser)) {
            // The user has specified a browser, but it can't be found.
            $output->writeln("<error>Browser not found: $browser</error>");
            $browser = false;
        }

        if ($browser) {
            $opened = $this->getHelper('shell')->execute(array($browser, $url));
            if ($opened) {
                $output->writeln("Opened: $url");
                return;
            }
        }

        $output->writeln($url);
    }


    /**
     * @param string $browser
     * @return bool
     */
    protected function browserExists($browser)
    {
        if (strpos(PHP_OS, 'WIN') !== false) {
            $args = array('where', $browser);
        }
        else {
            $args = array('command', '-v', $browser);
        }
        return (bool) $this->getHelper('shell')->execute($args);
    }

    /**
     * Find a default browser to use.
     *
     * @return string|false
     */
    protected function getDefaultBrowser()
    {
        $potential = array('xdg-open', 'open', 'start');
        $shellHelper = $this->getHelper('shell');
        foreach ($potential as $browser) {
            if ($shellHelper->execute(array('which', $browser))) {
                return $browser;
            }
        }
        return false;
    }

}
