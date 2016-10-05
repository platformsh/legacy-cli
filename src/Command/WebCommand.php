<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WebCommand extends UrlCommandBase
{

    protected function configure()
    {
        parent::configure();
        $this
            ->setName('web')
            ->setDescription('Open the Web UI');
        $this->addProjectOption()
             ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Attempt to select the appropriate project and environment.
        try {
            $this->validateInput($input);
        } catch (\Exception $e) {
            // Ignore errors.
        }

        if ($this->hasSelectedEnvironment()) {
            $url = $this->getSelectedEnvironment()->getUri();
        } elseif ($this->hasSelectedProject()) {
            $url = $this->getSelectedProject()->getLink('#ui');
        } else {
            $url = self::$config->get('service.accounts_url');
        }

        $this->openUrl($url, $input, $output);
    }
}
