<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\SubCommandRunner;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WelcomeCommand extends CommandBase
{
    protected static $defaultName = 'welcome';

    private $api;
    private $config;
    private $subCommandRunner;
    private $selector;

    public function __construct(
        Api $api,
        Config $config,
        SubCommandRunner $subCommandRunner,
        Selector $selector
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->subCommandRunner = $subCommandRunner;
        $this->selector = $selector;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Welcome to ' . $this->config->get('service.name'));
        $this->setHidden(true);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->stdErr->writeln("Welcome to " . $this->config->get('service.name') . "!\n");

        // Ensure the user is logged in in this parent command, because the
        // delegated commands below will not have interactive input.
        $this->api->getClient();

        $executable = $this->config->get('application.executable');

        if ($project = $this->selector->getCurrentProject()) {
            $projectUri = $project->getLink('#ui');
            $this->stdErr->writeln("Project title: <info>{$project->title}</info>");
            $this->stdErr->writeln("Project ID: <info>{$project->id}</info>");
            $this->stdErr->writeln("Project dashboard: <info>$projectUri</info>\n");

            // Warn if the project is suspended.
            if ($project->isSuspended()) {
                $messages = [];
                $messages[] = '<comment>This project is suspended.</comment>';
                if ($project->owner === $this->api->getMyAccount()['id']) {
                    $messages[] = '<comment>Update your payment details to re-activate it: '
                        . $this->config->get('service.accounts_url')
                        . '</comment>';
                }
                $messages[] = '';
                $this->stdErr->writeln($messages);
            }

            // Show the environments.
            $this->subCommandRunner->run('environment:list', ['--refresh' => 0]);
            $this->stdErr->writeln("\nYou can list other projects by running <info>$executable projects</info>\n");
        } else {
            // The project is not known. Show all projects.
            $this->subCommandRunner->run('project:list', ['--refresh' => 0]);
            $this->stdErr->writeln('');
        }

        $this->stdErr->writeln("Manage your SSH keys by running <info>$executable ssh-keys</info>\n");

        $this->stdErr->writeln("Type <info>$executable list</info> to see all available commands.");
    }
}
