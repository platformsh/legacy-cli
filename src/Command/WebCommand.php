<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

class WebCommand extends UrlCommandBase
{

    protected function configure()
    {
        parent::configure();
        $this
            ->setName('web')
            ->setDescription('Open the Platform.sh Web UI.')
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
        $this->loadConfig(false);
        if ($this->config) {
            $fakeOutput = new NullOutput();
            $this->validateInput($input, $fakeOutput);
        }

        $project = false;
        if ($this->project) {
            $project = $this->project;
        }
        elseif ($this->config) {
            // @todo a workaround while we don't have a unified UI
            $projects = $this->getProjects();
            $project = reset($projects);
        }

        if ($project) {
            $url = $project['uri'];
            if ($this->environment) {
                $url .= '/environments/' . $this->environment['id'];
            }
        }
        else {
            $url = 'https://platform.sh';
        }

        $this->openUrl($url, $input, $output);
    }
}
