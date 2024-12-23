<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\SshKey;
use Platformsh\Cli\Service\SubCommandRunner;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'welcome', description: 'Default (welcome) command')]
class WelcomeCommand extends CommandBase
{
    protected bool $hiddenInList = true;

    public function __construct(
        private readonly Api      $api,
        private readonly Config   $config,
        private readonly Selector $selector,
        private readonly SshKey   $sshKey,
        private readonly SubCommandRunner $subCommandRunner,
    ) {
        parent::__construct();
    }

    /**
     * @return null|array{projectId: string, environmentId: string, appName: string}
     */
    private function containerEnvironment(): ?array
    {
        $envPrefix = $this->config->getStr('service.env_prefix');
        $projectId = getenv($envPrefix . 'PROJECT');
        $environmentId = getenv($envPrefix . 'BRANCH');
        if ($projectId && $environmentId) {
            return [
                'projectId' => $projectId,
                'environmentId' => $environmentId,
                'appName' => getenv($envPrefix . 'APPLICATION_NAME') ?: '',
            ];
        }
        return null;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->stdErr->writeln("Welcome to " . $this->config->getStr('service.name') . "!\n");

        $containerEnv = $this->containerEnvironment();

        if ($project = $this->selector->getCurrentProject()) {
            $this->welcomeForLocalProjectDir($project);
        } elseif ($containerEnv) {
            $this->welcomeOnContainer($containerEnv);
        } else {
            $this->defaultWelcome();
        }

        $executable = $this->config->getStr('application.executable');

        $this->api->showSessionInfo();

        if ($this->api->isLoggedIn() && !$this->config->getBool('ssh.auto_load_cert')) {
            $sshKey = $this->sshKey;
            if (!$sshKey->hasLocalKey()) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln("To add an SSH key, run: <info>$executable ssh-key:add</info>");
            }
        }

        $this->stdErr->writeln('');
        $this->stdErr->writeln("To view all commands, run: <info>$executable list</info>");
        return 0;
    }

    /**
     * Display default welcome message, when not in a project directory.
     */
    private function defaultWelcome(): void
    {
        // The project is not known. Show all projects.
        $this->subCommandRunner->run('projects', ['--refresh' => '0']);
    }

    /**
     * Display welcome for a local project directory.
     *
     * @param Project $project
     */
    private function welcomeForLocalProjectDir(Project $project): void
    {
        $this->stdErr->writeln("Project: " . $this->api->getProjectLabel($project));
        if ($this->config->getBool('api.organizations')) {
            $org = $this->api->getOrganizationById($project->getProperty('organization'));
            if ($org) {
                $this->stdErr->writeln("Organization: " . $this->api->getOrganizationLabel($org));
            }
        }
        $this->stdErr->writeln("Console URL: <info>" . $this->api->getConsoleURL($project) . "</info>\n");

        if ($project->isSuspended()) {
            $this->api->warnIfSuspended($project);
        } else {
            // Show the environments.
            $this->subCommandRunner->run('environments', [
                '--project' => $project->id,
            ]);
        }

        $executable = $this->config->getStr('application.executable');
        $this->stdErr->writeln("\nYou can list other projects by running <info>$executable projects</info>");
    }

    /**
     * Display welcome when the user is in a cloud container environment.
     *
     * @param array{projectId: string, environmentId: string, appName: string} $containerEnvironment
     */
    private function welcomeOnContainer(array $containerEnvironment): void
    {
        $envPrefix = $this->config->getStr('service.env_prefix');
        $executable = $this->config->getStr('application.executable');

        $project = false;
        $environment = false;
        if ($this->api->isLoggedIn()) {
            $project = $this->api->getProject($containerEnvironment['projectId']);
            if ($project) {
                $environment = $this->api->getEnvironment($containerEnvironment['environmentId'], $project);
            }
        }

        if ($project) {
            $this->stdErr->writeln('Project: ' . $this->api->getProjectLabel($project));
            if ($environment) {
                $this->stdErr->writeln('Environment: ' . $this->api->getEnvironmentLabel($environment));
            }
            if ($containerEnvironment['appName']) {
                $this->stdErr->writeln('Application name: <info>' . $containerEnvironment['appName'] . '</info>');
            }

            if ($project->isSuspended()) {
                $this->api->warnIfSuspended($project);
                return;
            }
        } else {
            $this->stdErr->writeln('Project ID: <info>' . $containerEnvironment['projectId'] . '</info>');
            if ($containerEnvironment['environmentId']) {
                $this->stdErr->writeln('Environment ID: <info>' . $containerEnvironment['environmentId'] . '</info>');
            }
            if ($containerEnvironment['appName']) {
                $this->stdErr->writeln('Application name: <info>' . $containerEnvironment['appName'] . '</info>');
            }
        }

        $examples = [];
        if (getenv($envPrefix . 'APPLICATION')) {
            $examples[] = "To view application config, run: <info>$executable app:config</info>";
            $examples[] = "To view mounts, run: <info>$executable mounts</info>";
        }
        if (getenv($envPrefix . 'RELATIONSHIPS')) {
            $examples[] = "To view relationships, run: <info>$executable relationships</info>";
        }
        if (getenv($envPrefix . 'ROUTES')) {
            $examples[] = "To view routes, run: <info>$executable routes</info>";
        }
        if (getenv($envPrefix . 'VARIABLES')) {
            $examples[] = "To view variables, run: <info>$executable decode \${$envPrefix}VARIABLES</info>";
        }
        if (!empty($examples)) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('Local environment commands:');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(preg_replace('/^/m', '  ', implode("\n", $examples)));
        }
    }
}
