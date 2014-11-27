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

        $shellHelper = $this->getHelper('shell');
        if ($browser === '0') {
            // The user has requested not to use a browser.
            $browser = false;
        }
        elseif (empty($browser)) {
            // Find a default browser to use.
            $defaults = array('xdg-open', 'open', 'start');
            foreach ($defaults as $default) {
                if ($shellHelper->execute(array('which', $default))) {
                    $browser = $default;
                    break;
                }
            }
        }
        elseif (!$shellHelper->execute(array('which', $browser))) {
            // The user has specified a browser, but it can't be found.
            $output->writeln("<error>Browser not found: $browser</error>");
            $browser = false;
        }

        $output->writeln($url);

        if ($browser) {
            $shellHelper->execute(array($browser, $url));
        }
    }

}