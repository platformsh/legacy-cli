<?php

namespace Platformsh\Cli\Command;

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
        $this->addProjectOption()
             ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($this->isLoggedIn()) {
            // If the user is logged in, select the appropriate project and
            // environment.
            $this->validateInput($input, new NullOutput());
            $project = $this->getSelectedProject();
        }

        $url = 'https://marketplace.commerceguys.com/platform/login';
        if (!empty($project)) {
            $url = $project->getLink('#ui');
            if ($this->hasSelectedEnvironment()) {
                $environment = $this->getSelectedEnvironment();
                $url .= '/environments/' . $environment['id'];
            }
        }

        $this->openUrl($url, $input, $output);
    }
}
