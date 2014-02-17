<?php

namespace CommerceGuys\Command;

use CommerceGuys\Guzzle\Plugin\Oauth2\Oauth2Plugin;
use CommerceGuys\Guzzle\Plugin\Oauth2\GrantType\PasswordCredentials;
use CommerceGuys\Guzzle\Plugin\Oauth2\GrantType\RefreshToken;
use Guzzle\Service\Client;
use Guzzle\Service\Description\ServiceDescription;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentCommand extends PlatformCommand
{

    protected $project;
    protected $environment;
    protected $environments;

    protected function validateArguments(InputInterface $input, OutputInterface $output)
    {
        $arguments = $this->getDefinition()->getArguments();
        if (isset($arguments['project-id'])) {
            $projectId = $input->getArgument('project-id');
            if (empty($projectId)) {
                $output->writeln("<error>You must specify a project.</error>");
                return;
            }
            $projects = $this->getProjects();
            if (!isset($projects[$projectId])) {
                $output->writeln("<error>Project not found.</error>");
                return;
            }
            // Store the current project for easier access in other methods.
            $this->project = $projects[$projectId];
        }
        if (isset($arguments['environment-id'])) {
            $environmentId = $input->getArgument('environment-id');
            if (empty($environmentId)) {
                $output->writeln("<error>You must specify an environment.</error>");
                return;
            }
            $environments = $this->getEnvironments();
            if (!isset($environments[$environmentId])) {
                $output->writeln("<error>Environment not found.</error>");
                return;
            }
            // Store the current environment for easier access in other methods.
            $this->environment = $environments[$environmentId];
        }

        return TRUE;
    }

    /**
     * Return the user's environments for the given project.
     *
     * @return array The user's environments.
     */
    protected function getEnvironments()
    {
        if (!$this->environments) {
            $client = $this->getPlatformClient($this->project['endpoint']);
            $this->environments = array();
            foreach ($client->getEnvironments() as $environment) {
                $environment['endpoint'] = $environment['_links']['self']['href'];
                $this->environments[$environment['id']] = $environment;
            }
        }

        return $this->environments;
    }
}
