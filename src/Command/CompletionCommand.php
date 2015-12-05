<?php

namespace Platformsh\Cli\Command;

use Stecman\Component\Symfony\Console\BashCompletion\Completion;
use Stecman\Component\Symfony\Console\BashCompletion\CompletionCommand as ParentCompletionCommand;

class CompletionCommand extends ParentCompletionCommand implements CanHideInListInterface
{

    /** @var CommandBase */
    protected $platformCommand;

    /**
     * A list of the user's projects.
     * @var array
     */
    protected $projects;

    /**
     * {@inheritdoc}
     */
    public function hideInList()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function setUp()
    {
        $this->platformCommand = new WelcomeCommand();
        $this->platformCommand->setApplication($this->getApplication());
        $this->projects = $this->getProjects();
    }

    /**
     * @inheritdoc
     */
    protected function runCompletion()
    {
        $this->setUp();
        $projectIds = array_keys($this->projects);

        $this->handler->addHandlers([
            new Completion(
              'project:get',
              'id',
              Completion::TYPE_ARGUMENT,
              $projectIds
            ),
            Completion::makeGlobalHandler(
              'project',
              Completion::TYPE_OPTION,
              $projectIds
            ),
            Completion::makeGlobalHandler(
              'environment',
              Completion::TYPE_ARGUMENT,
              [$this, 'getEnvironments']
            ),
            Completion::makeGlobalHandler(
              'environment',
              Completion::TYPE_OPTION,
              [$this, 'getEnvironments']
            ),
            new Completion(
              'environment:branch',
              'parent',
              Completion::TYPE_ARGUMENT,
              [$this, 'getEnvironments']
            ),
            new Completion(
              'environment:checkout',
              'id',
              Completion::TYPE_ARGUMENT,
              [$this, 'getEnvironmentsForCheckout']
            ),
            new Completion\ShellPathCompletion(
              'ssh-key:add',
              'path',
              Completion::TYPE_ARGUMENT
            ),
            new Completion\ShellPathCompletion(
              'domain:add',
              'cert',
              Completion::TYPE_OPTION
            ),
            new Completion\ShellPathCompletion(
              'domain:add',
              'key',
              Completion::TYPE_OPTION
            ),
            new Completion\ShellPathCompletion(
              'domain:add',
              'chain',
              Completion::TYPE_OPTION
            ),
            new Completion\ShellPathCompletion(
              'local:build',
              'source',
              Completion::TYPE_OPTION
            ),
            new Completion\ShellPathCompletion(
              'local:build',
              'destination',
              Completion::TYPE_OPTION
            ),
            new Completion\ShellPathCompletion(
              'environment:sql-dump',
              'file',
              Completion::TYPE_OPTION
            ),
            new Completion\ShellPathCompletion(
              'local:init',
              'directory',
              Completion::TYPE_ARGUMENT
            ),
        ]);

        try {
            return $this->handler->runCompletion();
        } catch (\Exception $e) {
            // Suppress exceptions so that they are not displayed during
            // completion.
        }

        return [];
    }

    /**
     * Get a list of project IDs.
     *
     * @return array
     */
    protected function getProjects()
    {
        // Check that the user is logged in.
        $client = $this->platformCommand->getClient(false);
        if (!$client->getConnector()
                    ->isLoggedIn()
        ) {
            return [];
        }

        return $this->platformCommand->getProjects();
    }

    /**
     * Get a list of environments IDs that can be checked out.
     *
     * @return string[]
     */
    public function getEnvironmentsForCheckout()
    {
        $project = $this->platformCommand->getCurrentProject();
        if (!$project) {
            return [];
        }
        try {
            $currentEnvironment = $this->platformCommand->getCurrentEnvironment($project);
        } catch (\Exception $e) {
            $currentEnvironment = false;
        }
        $environments = $this->platformCommand->getEnvironments($project, false, false);
        if ($currentEnvironment) {
            $environments = array_filter(
              $environments,
              function ($environment) use ($currentEnvironment) {
                  return $environment->id !== $currentEnvironment->id;
              }
            );
        }

        return array_keys($environments);
    }

    /**
     * Get a list of environment IDs.
     *
     * The project is either defined by an ID that the user has specified in
     * the command (via the 'id' argument of 'get', or the '--project' option),
     * or it is determined from the current path.
     *
     * @return string[]
     */
    public function getEnvironments()
    {
        if (!$this->projects) {
            return [];
        }
        $commandLine = $this->handler->getContext()
                                     ->getCommandLine();
        $currentProjectId = $this->getProjectIdFromCommandLine($commandLine);
        if (!$currentProjectId && ($currentProject = $this->platformCommand->getCurrentProject())) {
            $project = $currentProject;
        } elseif (isset($this->projects[$currentProjectId])) {
            $project = $this->projects[$currentProjectId];
        } else {
            return [];
        }
        $environments = $this->platformCommand->getEnvironments($project, false, false);

        return array_keys($environments);
    }

    /**
     * Get the project ID the user has already entered on the command line.
     *
     * @param string $commandLine
     *
     * @return string|false
     */
    protected function getProjectIdFromCommandLine($commandLine)
    {
        if (preg_match('/\W(\-\-project|\-p|get) ?=? ?[\'"]?([0-9a-z]+)[\'"]?/', $commandLine, $matches)) {
            return $matches[2];
        }

        return false;
    }

}
