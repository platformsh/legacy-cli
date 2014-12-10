<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

class WebCommand extends UrlCommandBase
{

    protected function configure()
    {
        parent::configure();
        $this
            ->setName('web')
            ->setDescription('Open the Platform.sh Web UI');
        $this->addProjectOption()->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Check whether the user is logged in (do not require login).
        $this->loadConfig(false);
        if ($this->config) {
            // If the user is logged in, select the appropriate project and
            // environment, suppressing any errors.
            $this->validateInput($input, new NullOutput());
        }

        $url = 'https://marketplace.commerceguys.com/platform/login';
        if ($this->project) {
            $url = $this->project['uri'];
            if ($this->environment) {
                $url .= '/environments/' . $this->environment['id'];
            }
        }

        $this->openUrl($url, $input, $output);
    }
}
