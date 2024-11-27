<?php

namespace Platformsh\Cli\Command;

use Stecman\Component\Symfony\Console\BashCompletion\Completion\ShellPathCompletion;
use Platformsh\Cli\Local\ApplicationFinder;
use Platformsh\Cli\Service\Api;
use Platformsh\Client\Model\BasicProjectInfo;
use Platformsh\Client\Model\Project;
use Stecman\Component\Symfony\Console\BashCompletion\Completion;
use Stecman\Component\Symfony\Console\BashCompletion\CompletionCommand as ParentCompletionCommand;

class CompletionCommand extends ParentCompletionCommand
{

    /** @var Api */
    protected $api;

    /** @var CommandBase|null */
    private $welcomeCommand;

    /**
     * {@inheritdoc}
     */
    public function isHidden(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    protected function runCompletion()
    {
        $this->api = new Api();
        $projectInfos = $this->api->isLoggedIn() ? $this->api->getMyProjects(false) : [];
        $projectIds = array_map(function (BasicProjectInfo $p) { return $p->id; }, $projectInfos);

        $this->handler->addHandlers([
            new Completion(
                Completion::ALL_COMMANDS,
                'project',
                Completion::TYPE_OPTION,
                $projectIds
            ),
            new Completion(
                Completion::ALL_COMMANDS,
                'project',
                Completion::TYPE_ARGUMENT,
                $projectIds
            ),
            new Completion(
                Completion::ALL_COMMANDS,
                'environment',
                Completion::TYPE_ARGUMENT,
                [$this, 'getEnvironments']
            ),
            new Completion(
                Completion::ALL_COMMANDS,
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
            new Completion(
                'user:role',
                'level',
                Completion::TYPE_OPTION,
                ['project', 'environment']
            ),
            new ShellPathCompletion(
                'ssh-key:add',
                'path',
                Completion::TYPE_ARGUMENT
            ),
            new ShellPathCompletion(
                'domain:add',
                'cert',
                Completion::TYPE_OPTION
            ),
            new ShellPathCompletion(
                'domain:add',
                'key',
                Completion::TYPE_OPTION
            ),
            new ShellPathCompletion(
                'domain:add',
                'chain',
                Completion::TYPE_OPTION
            ),
            new ShellPathCompletion(
                'local:build',
                'source',
                Completion::TYPE_OPTION
            ),
            new ShellPathCompletion(
                'local:build',
                'destination',
                Completion::TYPE_OPTION
            ),
            new ShellPathCompletion(
                'environment:sql-dump',
                'file',
                Completion::TYPE_OPTION
            ),
            new ShellPathCompletion(
                'local:init',
                'directory',
                Completion::TYPE_ARGUMENT
            ),
            new Completion(
                Completion::ALL_COMMANDS,
                'app',
                Completion::TYPE_OPTION,
                [$this, 'getAppNames']
            ),
            new Completion(
                Completion::ALL_COMMANDS,
                'app',
                Completion::TYPE_OPTION,
                [$this, 'getAppNames']
            ),
            new ShellPathCompletion(
                Completion::ALL_COMMANDS,
                'identity-file',
                Completion::TYPE_OPTION
            ),
            new ShellPathCompletion(
                'server:run',
                'log',
                Completion::TYPE_OPTION
            ),
            new ShellPathCompletion(
                'server:start',
                'log',
                Completion::TYPE_OPTION
            ),
            new ShellPathCompletion(
                'service:mongo:restore',
                'archive',
                Completion::TYPE_ARGUMENT
            ),
            new ShellPathCompletion(
                'integration:add',
                'file',
                Completion::TYPE_OPTION
            ),
            new ShellPathCompletion(
                'integration:update',
                'file',
                Completion::TYPE_OPTION
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
     * @return WelcomeCommand
     */
    protected function getWelcomeCommand()
    {
        if (!isset($this->welcomeCommand)) {
            $this->welcomeCommand = new WelcomeCommand('welcome');
            $this->welcomeCommand->setApplication($this->getApplication());
        }

        return $this->welcomeCommand;
    }

    /**
     * Get a list of environments IDs that can be checked out.
     *
     * @return string[]
     */
    public function getEnvironmentsForCheckout()
    {
        $project = $this->getWelcomeCommand()->getCurrentProject(true);
        if (!$project) {
            return [];
        }
        try {
            $currentEnvironment = $this->getWelcomeCommand()->getCurrentEnvironment($project, false);
        } catch (\Exception $e) {
            $currentEnvironment = false;
        }
        $environments = $this->api->getEnvironments($project, false, false);
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
     * Get a list of application names in the local project.
     *
     * @return string[]
     */
    public function getAppNames()
    {
        $apps = [];
        if ($projectRoot = $this->getWelcomeCommand()->getProjectRoot()) {
            $finder = new ApplicationFinder();
            foreach ($finder->findApplications($projectRoot) as $app) {
                $name = $app->getName();
                if ($name !== null) {
                    $apps[] = $name;
                }
            }
        } elseif ($project = $this->getProject()) {
            $environments = $this->api->getEnvironments($project, false);
            if ($environments && ($environment = $this->api->getDefaultEnvironment($environments, $project))) {
                $apps = array_keys($environment->getSshUrls());
            }
        }

        return $apps;
    }

    /**
     * Get the preferred project for autocompletion.
     *
     * The project is either defined by an ID that the user has specified in
     * the command (via the 'project' argument or '--project' option), or it is
     * determined from the current path.
     *
     * @return Project|false
     */
    protected function getProject()
    {
        $commandLine = $this->handler->getContext()
            ->getCommandLine();
        $currentProjectId = $this->getProjectIdFromCommandLine($commandLine);
        if (!$currentProjectId && ($currentProject = $this->getWelcomeCommand()->getCurrentProject(true))) {
            return $currentProject;
        }

        return $this->api->getProject($currentProjectId, null, false);
    }

    /**
     * Get a list of environment IDs.
     *
     * @return string[]
     */
    public function getEnvironments()
    {
        $project = $this->getProject();
        if (!$project) {
            return [];
        }

        return array_keys($this->api->getEnvironments($project, false, false));
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
