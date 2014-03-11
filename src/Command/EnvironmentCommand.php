<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Guzzle\Plugin\Oauth2\Oauth2Plugin;
use CommerceGuys\Guzzle\Plugin\Oauth2\GrantType\PasswordCredentials;
use CommerceGuys\Guzzle\Plugin\Oauth2\GrantType\RefreshToken;
use Guzzle\Service\Client;
use Guzzle\Service\Description\ServiceDescription;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Parser;

class EnvironmentCommand extends PlatformCommand
{

    protected $project;
    protected $environment;
    protected $environments;

    protected function validateInput(InputInterface $input, OutputInterface $output)
    {
        $options = $this->getDefinition()->getOptions();
        // Allow the project to be specified explicitly via --project.
        $projectId = isset($options['projects']) ? $input->getOption('project') : null;
        if (!empty($projectId)) {
            $projects = $this->getProjects();
            if (!isset($projects[$projectId])) {
                $output->writeln("<error>Specified project not found.</error>");
                return;
            }
            $this->project = $projects[$projectId];
        } else {
            // Autodetect the project if the user is in a project directory.
            $this->project = $this->getCurrentProject();
            if (!$this->project) {
                $output->writeln("<error>Could not determine the current project.</error>");
                $output->writeln("<error>Specify it manually using --project or go to a project directory.</error>");
                return;
            }
        }

        if (isset($options['environment'])) {
            // Allow the environment to be specified explicitly via --environment.
            $environmentId = $input->getOption('environment');
            if (!empty($environmentId)) {
                $environments = $this->getEnvironments($this->project);
                if (!isset($environments[$environmentId])) {
                    $output->writeln("<error>Specified environment not found.</error>");
                    return;
                }
                $this->environment = $environments[$environmentId];
            } else {
                // Autodetect the environment if the user is in a project directory.
                $this->environment = $this->getCurrentEnvironment($this->project);
                if (!$this->environment) {
                    $output->writeln("<error>Could not determine the current environment.</error>");
                    $output->writeln("<error>Specify it manually using --environment or go to a project directory.</error>");
                    return;
                }
            }
        }

        return true;
    }

    /**
     * @return bool Whether the operation is allowed on the current environment.
     */
    protected function operationAllowed($operation)
    {
        return $this->environment && isset($this->environment['_links']['#' . $operation]);
    }
}
