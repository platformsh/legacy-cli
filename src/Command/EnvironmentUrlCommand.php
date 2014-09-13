<?php

namespace CommerceGuys\Platform\Cli\Command;

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
            ->setDescription('Get the public URL to an environment, and open it in a browser.')
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
                'The project id'
            )
            ->addOption(
                'environment',
                null,
                InputOption::VALUE_OPTIONAL,
                'The environment id'
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
        if ($input->getOption('pipe')) {
            $output->write($url);
            return;
        }

        $browser = $input->getOption('browser');

        if ($browser === '0') {
            // The user has requested not to use a browser.
            $browser = false;
        }
        elseif (empty($browser)) {
            // Find a default browser to use.
            $defaults = array('xdg-open', 'open', 'start');
            foreach ($defaults as $default) {
                if (shell_exec("which $default")) {
                    $browser = $default;
                    break;
                }
            }
        }
        elseif (!shell_exec('which ' . escapeshellarg($browser))) {
            // The user has specified a browser, but it can't be found.
            $output->writeln("<error>Browser not found: $browser</error>");
            $browser = false;
        }

        $output->writeln($url);

        if ($browser) {
            shell_exec($browser . ' ' . escapeshellarg($url));
        }
    }
}
