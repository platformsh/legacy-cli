<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentUrlCommand extends UrlCommandBase
{

    protected function configure()
    {
        parent::configure();
        $this
          ->setName('environment:url')
          ->setAliases(array('url'))
          ->setDescription('Get the public URL of an environment')
          ->addArgument(
            'path',
            InputArgument::OPTIONAL,
            'A path to append to the URL.'
          )
          ->addOption('no-wait', null, InputOption::VALUE_NONE, "Do not wait for the environment to become active first");
        $this->addProjectOption()
             ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $selectedEnvironment = $this->getSelectedEnvironment();

        $this->waitUntilEnvironmentActive($selectedEnvironment, $this->getSelectedProject(), $input);

        if (!$selectedEnvironment->hasLink('public-url')) {
            $this->stdErr->writeln('No URL available. The environment may be inactive.');
            return 1;
        }

        $url = $selectedEnvironment->getLink('public-url', true);

        $path = $input->getArgument('path');
        if ($path) {
            $url .= trim($path);
        }

        $this->openUrl($url, $input, $output);
        return 0;
    }
}
