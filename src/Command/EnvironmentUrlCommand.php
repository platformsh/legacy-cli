<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentUrlCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:url')
            ->setAliases(array('url'))
            ->setDescription('Get the public URL of an environment')
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'A path to append to the URL.'
            )
            ->addOption(
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
            )
            ->addOption(
                'project',
                null,
                InputOption::VALUE_OPTIONAL,
                'The project ID'
            )
            ->addOption(
                'environment',
                null,
                InputOption::VALUE_OPTIONAL,
                'The environment ID'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return;
        }

        if (empty($this->environment['_links']['public-url']['href'])) {
            throw new \Exception('No URL available');
        }

        $url = $this->environment['_links']['public-url']['href'];

        $path = $input->getArgument('path');
        if ($path) {
            $url .= trim($path);
        }

        if ($input->getOption('pipe') || !$this->isTerminal($output)) {
            $output->write($url);
            return;
        }

        $browser = $input->getOption('browser');

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
