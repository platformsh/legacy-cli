<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Service\Url;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WebCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('web')
            ->setDescription('Open the Web UI');
        Url::configureInput($this->getDefinition());
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

        $url = $this->config()->get('service.accounts_url');
        if ($this->hasSelectedProject()) {
            $url = $this->getSelectedProject()->getLink('#ui');
            if ($this->hasSelectedEnvironment()) {
                $url .= '/environments/' . rawurlencode($this->getSelectedEnvironment()->id);
            }
        }

        /** @var \Platformsh\Cli\Service\Url $url */
        $url = $this->getService('url');
        $url->openUrl($url);
    }
}
