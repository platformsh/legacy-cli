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
        // Attempt to select the appropriate project and environment.
        try {
            $this->validateInput($input, new NullOutput());
        }
        catch (\Exception $e) {
            // Ignore errors.
        }

        $project = $this->hasSelectedProject() ? $this->getSelectedProject() : false;

        $url = 'https://marketplace.commerceguys.com/platform/login';
        if ($project) {
            $url = $project->getLink('#ui');
            if ($this->hasSelectedEnvironment()) {
                $environment = $this->getSelectedEnvironment();
                $url .= '/environments/' . $environment['id'];
            }
        }

        $this->openUrl($url, $input, $output);
    }
}
