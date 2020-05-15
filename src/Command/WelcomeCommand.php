<?php

namespace Platformsh\Cli\Command;

use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WelcomeCommand extends CommandBase
{
    protected $hiddenInList = true;
    protected $local = true;

    protected function configure()
    {
        $this
            ->setName('welcome')
            ->setDescription('Welcome to ' . $this->config()->get('service.name'));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
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

        if ($this->api()->isLoggedIn() && !$this->config()->get('api.auto_load_ssh_cert')) {
            $this->stdErr->writeln("Manage your SSH keys by running <info>$executable ssh-keys</info>\n");
        }

        $this->stdErr->writeln("To view all commands, run: <info>$executable list</info>");
    }

    /**
     * Display default welcome message, when not in a project directory.
     */
    private function defaultWelcome()
    {
        // The project is not known. Show all projects.
        $this->runOtherCommand('projects', ['--refresh' => 0]);
        $this->stdErr->writeln('');
    }

    /**
     * Display welcome for a local project directory.
     *
     * @param \Platformsh\Client\Model\Project $project
     */
    private function welcomeForLocalProjectDir(Project $project)
    {
        $projectUri = $project->getLink('#ui');
        $this->stdErr->writeln("Project title: <info>{$project->title}</info>");
        $this->stdErr->writeln("Project ID: <info>{$project->id}</info>");
        $this->stdErr->writeln("Project dashboard: <info>$projectUri</info>\n");

        // Show the environments.
        $this->runOtherCommand('environments', [
            '--project' => $project->id,
        ]);
        $executable = $this->config()->get('application.executable');
        $this->stdErr->writeln("\nYou can list other projects by running <info>$executable projects</info>\n");
    }

    /**
     * Warn the user if a project is suspended.
     *
     * @param \Platformsh\Client\Model\Project $project
     */
    private function warnIfSuspended(Project $project)
    {
        if ($project->isSuspended()) {
            $messages = [];
            $messages[] = '<comment>This project is suspended.</comment>';
            if ($project->owner === $this->api()->getMyAccount()['id']) {
                $messages[] = '<comment>Update your payment details to re-activate it: '
                    . $this->config()->get('service.accounts_url')
                    . '</comment>';
            }
            $messages[] = '';
            $this->stdErr->writeln($messages);
        }
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

            $this->warnIfSuspended($project);
        } else {
            $this->stdErr->writeln('Project ID: <info>' . $projectId . '</info>');
            if ($environmentId) {
                $this->stdErr->writeln('Environment ID: <info>' . $environmentId . '</info>');
            }
            if ($appName) {
                $this->stdErr->writeln('Application name: <info>' . $appName . '</info>');
            }
        }

        $this->stdErr->writeln('');
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
            $this->stdErr->writeln('Local environment commands:');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(preg_replace('/^/m', '  ', implode("\n", $examples)));
            $this->stdErr->writeln('');
        }
    }
}
