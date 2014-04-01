<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentCommand extends PlatformCommand
{

    protected $project;
    protected $environment;
    protected $environments;

    protected function validateInput(InputInterface $input, OutputInterface $output)
    {
        // Allow the project to be specified explicitly via --project.
        $projectId = $input->hasOption('project') ? $input->getOption('project') : null;
        if (!empty($projectId)) {
            $project = $this->getProject($projectId);
            if (!$project) {
                $output->writeln("<error>Specified project not found.</error>");
                return;
            }
            $this->project = $project;
        } else {
            // Autodetect the project if the user is in a project directory.
            $this->project = $this->getCurrentProject();
            if (!$this->project) {
                $output->writeln("<error>Could not determine the current project.</error>");
                $output->writeln("<error>Specify it manually using --project or go to a project directory.</error>");
                return;
            }
        }

        if ($input->hasOption('environment')) {
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
    protected function operationAllowed($operation, $environment = null)
    {
        $environment = $environment ?: $this->environment;
        return $environment && isset($environment['_links']['#' . $operation]);
    }
}
