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
        $projects = $this->getProjects();
        // Allow the project to be specified explicitly via --project.
        $projectId = $input->getOption('project');
        if (!empty($projectId)) {
            if (!isset($projects[$projectId])) {
                $output->writeln("<error>Specified project not found.</error>");
                return;
            }
            $this->project = $projects[$projectId];
        }
        else {
            // Try to autodetect the project by finding .platform-project
            // in the current directory or one of its parents.
            $currentDir = getcwd();
            $homeDir = trim(shell_exec('cd ~ && pwd'));
            while (!$this->project) {
                if (file_exists($currentDir . '/.platform-project')) {
                    $yaml = new Parser();
                    $projectConfig = $yaml->parse(file_get_contents($currentDir . '/.platform-project'));
                    $this->project = $projects[$projectConfig['id']];
                    break;
                }

                // The file was not found, go one directory up.
                $dirParts = explode('/', $currentDir);
                array_pop($dirParts);
                $currentDir = implode('/', $dirParts);
                if ($currentDir == $homeDir) {
                    // We've reached the home directory, stop here.
                    break;
                }
            }

            if (!$this->project) {
                $output->writeln("<error>Could not determine the current project.</error>");
                $output->writeln("<error>Specify it manually using --project or go to a project directory.</error>");
                return;
            }
        }

        $arguments = $this->getDefinition()->getArguments();
        if (isset($arguments['environment-id'])) {
            $environmentId = $input->getArgument('environment-id');
            if (empty($environmentId)) {
                $output->writeln("<error>You must specify an environment.</error>");
                return;
            }
            $environments = $this->getEnvironments($this->project);
            if (!isset($environments[$environmentId])) {
                $output->writeln("<error>Environment not found.</error>");
                return;
            }
            // Store the current environment for easier access in other methods.
            $this->environment = $environments[$environmentId];
        }

        return TRUE;
    }
}
