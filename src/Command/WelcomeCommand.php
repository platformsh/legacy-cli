<?php

namespace Platformsh\Cli\Command;

use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'welcome', description: 'Default (welcome) command')]
class WelcomeCommand extends CommandBase
{
    protected $hiddenInList = true;
    protected $local = true;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->stdErr->writeln("Welcome to " . $this->config()->get('service.name') . "!\n");

        $envPrefix = $this->config()->get('service.env_prefix');
        $onContainer = getenv($envPrefix . 'PROJECT') && getenv($envPrefix . 'BRANCH');

        if ($project = $this->getCurrentProject()) {
            $this->welcomeForLocalProjectDir($project);
        } elseif ($onContainer) {
            $this->welcomeOnContainer();
        } else {
            $this->defaultWelcome();
        }

        $executable = $this->config()->get('application.executable');

        $this->api()->showSessionInfo();

        if ($this->api()->isLoggedIn() && !$this->config()->getWithDefault('ssh.auto_load_cert', false)) {
            /** @var \Platformsh\Cli\Service\SshKey $sshKey */
            $sshKey = $this->getService('ssh_key');
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
    private function defaultWelcome()
    {
        // The project is not known. Show all projects.
        $this->runOtherCommand('projects', ['--refresh' => '0']);
    }

    /**
     * Display welcome for a local project directory.
     *
     * @param \Platformsh\Client\Model\Project $project
     */
    private function welcomeForLocalProjectDir(Project $project)
    {
        $this->stdErr->writeln("Project: " . $this->api()->getProjectLabel($project));
        if ($this->config()->get('api.organizations')) {
            $org = $this->api()->getOrganizationById($project->getProperty('organization'));
            if ($org) {
                $this->stdErr->writeln("Organization: " . $this->api()->getOrganizationLabel($org));
            }
        }
        $this->stdErr->writeln("Console URL: <info>" . $this->api()->getConsoleURL($project) . "</info>\n");

        if ($project->isSuspended()) {
            $this->api()->warnIfSuspended($project);
        } else {
            // Show the environments.
            $this->runOtherCommand('environments', [
                '--project' => $project->id,
            ]);
        }

        $executable = $this->config()->get('application.executable');
        $this->stdErr->writeln("\nYou can list other projects by running <info>$executable projects</info>");
    }

    /**
     * Display welcome when the user is in a cloud container environment.
     */
    private function welcomeOnContainer()
    {
        $envPrefix = $this->config()->get('service.env_prefix');
        $executable = $this->config()->get('application.executable');

        $projectId = getenv($envPrefix . 'PROJECT');
        $environmentId = getenv($envPrefix . 'BRANCH');
        $appName = getenv($envPrefix . 'APPLICATION_NAME');

        $project = false;
        $environment = false;
        if ($this->api()->isLoggedIn()) {
            $project = $this->api()->getProject($projectId);
            if ($project && $environmentId) {
                $environment = $this->api()->getEnvironment($environmentId, $project);
            }
        }

        if ($project) {
            $this->stdErr->writeln('Project: ' . $this->api()->getProjectLabel($project));
            if ($environment) {
                $this->stdErr->writeln('Environment: ' . $this->api()->getEnvironmentLabel($environment));
            }
            if ($appName) {
                $this->stdErr->writeln('Application name: <info>' . $appName . '</info>');
            }

            if ($project->isSuspended()) {
                $this->api()->warnIfSuspended($project);
                return;
            }
        } else {
            $this->stdErr->writeln('Project ID: <info>' . $projectId . '</info>');
            if ($environmentId) {
                $this->stdErr->writeln('Environment ID: <info>' . $environmentId . '</info>');
            }
            if ($appName) {
                $this->stdErr->writeln('Application name: <info>' . $appName . '</info>');
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
