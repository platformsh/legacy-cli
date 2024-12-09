<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Selector\Selector;
use Stecman\Component\Symfony\Console\BashCompletion\Completion\CompletionInterface;
use Stecman\Component\Symfony\Console\BashCompletion\Completion\ShellPathCompletion;
use Platformsh\Cli\Local\ApplicationFinder;
use Platformsh\Cli\Service\Api;
use Platformsh\Client\Model\BasicProjectInfo;
use Platformsh\Client\Model\Project;
use Stecman\Component\Symfony\Console\BashCompletion\Completion;
use Stecman\Component\Symfony\Console\BashCompletion\CompletionCommand as ParentCompletionCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: '_completion', description: 'Autocompletion hook')]
class CompletionCommand extends ParentCompletionCommand
{
    public function __construct(private readonly Api $api, private readonly Selector $selector)
    {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    public function isHidden(): bool
    {
        return true;
    }

    protected function runCompletion(): array
    {
        $projectInfos = $this->api->isLoggedIn() ? $this->api->getMyProjects(false) : [];
        $projectIds = array_map(fn(BasicProjectInfo $p): string => $p->id, $projectInfos);

        $this->handler->addHandlers([
            new Completion(
                CompletionInterface::ALL_COMMANDS,
                'project',
                CompletionInterface::TYPE_OPTION,
                $projectIds
            ),
            new Completion(
                CompletionInterface::ALL_COMMANDS,
                'project',
                CompletionInterface::TYPE_ARGUMENT,
                $projectIds
            ),
            new Completion(
                CompletionInterface::ALL_COMMANDS,
                'environment',
                CompletionInterface::TYPE_ARGUMENT,
                [$this, 'getEnvironments'],
            ),
            new Completion(
                CompletionInterface::ALL_COMMANDS,
                'environment',
                CompletionInterface::TYPE_OPTION,
                [$this, 'getEnvironments'],
            ),
            new Completion(
                'environment:branch',
                'parent',
                CompletionInterface::TYPE_ARGUMENT,
                [$this, 'getEnvironments'],
            ),
            new Completion(
                'environment:checkout',
                'id',
                CompletionInterface::TYPE_ARGUMENT,
                [$this, 'getEnvironmentsForCheckout'],
            ),
            new Completion(
                'user:role',
                'level',
                CompletionInterface::TYPE_OPTION,
                ['project', 'environment']
            ),
            new ShellPathCompletion(
                'ssh-key:add',
                'path',
                CompletionInterface::TYPE_ARGUMENT
            ),
            new ShellPathCompletion(
                'domain:add',
                'cert',
                CompletionInterface::TYPE_OPTION
            ),
            new ShellPathCompletion(
                'domain:add',
                'key',
                CompletionInterface::TYPE_OPTION
            ),
            new ShellPathCompletion(
                'domain:add',
                'chain',
                CompletionInterface::TYPE_OPTION
            ),
            new ShellPathCompletion(
                'local:build',
                'source',
                CompletionInterface::TYPE_OPTION
            ),
            new ShellPathCompletion(
                'local:build',
                'destination',
                CompletionInterface::TYPE_OPTION
            ),
            new ShellPathCompletion(
                'environment:sql-dump',
                'file',
                CompletionInterface::TYPE_OPTION
            ),
            new ShellPathCompletion(
                'local:init',
                'directory',
                CompletionInterface::TYPE_ARGUMENT
            ),
            new Completion(
                CompletionInterface::ALL_COMMANDS,
                'app',
                CompletionInterface::TYPE_OPTION,
                [$this, 'getAppNames'],
            ),
            new Completion(
                CompletionInterface::ALL_COMMANDS,
                'app',
                CompletionInterface::TYPE_OPTION,
                [$this, 'getAppNames'],
            ),
            new ShellPathCompletion(
                CompletionInterface::ALL_COMMANDS,
                'identity-file',
                CompletionInterface::TYPE_OPTION
            ),
            new ShellPathCompletion(
                'server:run',
                'log',
                CompletionInterface::TYPE_OPTION
            ),
            new ShellPathCompletion(
                'server:start',
                'log',
                CompletionInterface::TYPE_OPTION
            ),
            new ShellPathCompletion(
                'service:mongo:restore',
                'archive',
                CompletionInterface::TYPE_ARGUMENT
            ),
            new ShellPathCompletion(
                'integration:add',
                'file',
                CompletionInterface::TYPE_OPTION
            ),
            new ShellPathCompletion(
                'integration:update',
                'file',
                CompletionInterface::TYPE_OPTION
            ),
        ]);

        try {
            return $this->handler->runCompletion();
        } catch (\Exception) {
            // Suppress exceptions so that they are not displayed during
            // completion.
        }

        return [];
    }

    /**
     * Get a list of environments IDs that can be checked out.
     *
     * @return string[]
     */
    public function getEnvironmentsForCheckout(): array
    {
        $project = $this->selector->getCurrentProject(true);
        if (!$project) {
            return [];
        }
        try {
            $currentEnvironment = $this->selector->getCurrentEnvironment($project, false);
        } catch (\Exception) {
            $currentEnvironment = false;
        }
        $environments = $this->api->getEnvironments($project, false, false);
        if ($currentEnvironment) {
            $environments = array_filter(
                $environments,
                fn($environment): bool => $environment->id !== $currentEnvironment->id
            );
        }

        return array_keys($environments);
    }

    /**
     * Get a list of application names in the local project.
     *
     * @return string[]
     */
    public function getAppNames(): array
    {
        $apps = [];
        if ($projectRoot = $this->selector->getProjectRoot()) {
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
    protected function getProject(): Project|false
    {
        $commandLine = $this->handler->getContext()
            ->getCommandLine();
        $currentProjectId = $this->getProjectIdFromCommandLine($commandLine);
        if (!$currentProjectId && ($currentProject = $this->selector->getCurrentProject(true))) {
            return $currentProject;
        }

        return $this->api->getProject($currentProjectId, null, false);
    }

    /**
     * Get a list of environment IDs.
     *
     * @return string[]
     */
    public function getEnvironments(): array
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
    protected function getProjectIdFromCommandLine(string $commandLine): string|false
    {
        if (preg_match('/\W(--project|-p|get) ?=? ?[\'"]?([0-9a-z]+)[\'"]?/', $commandLine, $matches)) {
            return $matches[2];
        }

        return false;
    }
}
