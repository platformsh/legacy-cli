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

        $url = 'https://platform.sh';
        if ($this->project) {
            $url = $this->project['uri'];
            if ($this->environment) {
                $url .= '/environments/' . $this->environment['id'];
            }
        }
        elseif ($this->config) {
            // If no project is selected, find the appropriate Web UI hostname
            // for the user's first project.
            // @todo update this when there is a unified UI domain
            $projects = $this->getProjects();
            $project = reset($projects);
            $parsed = parse_url($project['uri']);
            if (!$parsed) {
                throw new \RuntimeException("Failed to parse project URL");
            }
            $url = 'https://' . $parsed['host'];
        }

        $this->openUrl($url, $input, $output);
    }
}
