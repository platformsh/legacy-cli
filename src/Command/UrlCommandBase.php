<?php

namespace Platformsh\Cli\Command;

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
          InputOption::VALUE_REQUIRED,
          'The browser to use to open the URL. Set 0 for none.'
        )->addOption(
          'pipe',
          null,
          InputOption::VALUE_REQUIRED,
          'Output the raw URL, suitable for piping to another command.'
        );
    }

    /**
     * Open a URL in the browser, or print it.
     *
     * @param string          $url
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

        $shellHelper = $this->getHelper('shell');

        if ($browser === '0') {
            // The user has requested not to use a browser.
            $browser = false;
        } elseif (empty($browser)) {
            // Find a default browser to use.
            $browser = $this->getDefaultBrowser();
        } elseif (!$shellHelper->commandExists($browser)) {
            // The user has specified a browser, but it can't be found.
            $this->stdErr->writeln("<error>Browser not found: $browser</error>");
            $browser = false;
        }

        if ($browser) {
            $opened = $shellHelper->execute(array($browser, $url));
            if ($opened) {
                $this->stdErr->writeln("Opened: $url");

                return;
            }
        }

        $output->writeln($url);
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
            if ($shellHelper->commandExists($browser)) {
                return $browser;
            }
        }

        return false;
    }

}
