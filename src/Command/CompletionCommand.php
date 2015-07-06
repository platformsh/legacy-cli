<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Console\ArgvInput;
use Stecman\Component\Symfony\Console\BashCompletion\Completion;
use Stecman\Component\Symfony\Console\BashCompletion\CompletionCommand as ParentCompletionCommand;

class CompletionCommand extends ParentCompletionCommand
{

    /** @var PlatformCommand */
    protected $platformCommand;

    /**
     * A list of the user's projects.
     * @var array
     */
    protected $projects;

    public function isEnabled()
    {
        // Hide the command in the list.
        global $argv;

        return !isset($argv[1]) || $argv[1] != 'list';
    }

    public function isLocal()
    {
        return true;
    }

    protected function setUp()
    {
        $this->platformCommand = new WelcomeCommand();
        $this->platformCommand->setApplication($this->getApplication());
        $this->setUpShortcut();
        $this->projects = $this->getProjects();
    }

    protected function setUpShortcut()
    {
        $cmd = $this->handler->getContext()->getCommandLine();
        $input = new ArgvInput(explode(' ', $cmd));
        if ($input->getShortcut()) {
            $this->handler->getContext()->setCommandLine('platform ' . $input->__toString());
            $this->platformCommand->loadShortcut($input->getShortcut());
        }
    }

    /**
     * @inheritdoc
     */
    protected function runCompletion()
    {
        $this->setUp();
        $projectIds = array_keys($this->projects);

        $this->handler->addHandlers(
          array(
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
              array($this, 'getEnvironments')
            ),
            Completion::makeGlobalHandler(
              'environment',
              Completion::TYPE_OPTION,
              array($this, 'getEnvironments')
            ),
            new Completion(
              'environment:branch',
              'parent',
              Completion::TYPE_ARGUMENT,
              array($this, 'getEnvironments')
            ),
            new Completion(
              'environment:checkout',
              'id',
              Completion::TYPE_ARGUMENT,
              array($this, 'getEnvironmentsForCheckout')
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
            )
          )
        );

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
            return array();
        }

        return $this->platformCommand->getProjects();
    }

    protected function getProject()
    {
        if ($this->platformCommand->hasSelectedProject()) {
            return $this->platformCommand->getSelectedProject();
        }
        elseif ($project = $this->platformCommand->getCurrentProject()) {
            return $project;
        }

        return false;
    }

    /**
     * Get a list of environments IDs that can be checked out.
     *
     * @return string[]
     */
    public function getEnvironmentsForCheckout()
    {
        if (!$project = $this->getProject()) {
            return array();
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
                  return $environment['id'] != $currentEnvironment['id'];
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
            return array();
        }
        $commandLine = $this->handler->getContext()
                                     ->getCommandLine();
        $currentProjectId = $this->getProjectIdFromCommandLine($commandLine);
        if (!$currentProjectId && ($currentProject = $this->getProject())) {
            $project = $currentProject;
        } elseif (isset($this->projects[$currentProjectId])) {
            $project = $this->projects[$currentProjectId];
        } else {
            return array();
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
        if (preg_match('/\W(\-\-project|get) ?=? ?[\'"]?([0-9a-z]+)[\'"]?/', $commandLine, $matches)) {
            return $matches[2];
        }

        return false;
    }

}
