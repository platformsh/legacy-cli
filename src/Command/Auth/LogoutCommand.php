<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Auth;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\SshConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LogoutCommand extends CommandBase
{
    protected static $defaultName = 'auth:logout|logout';

    private $api;
    private $config;
    private $sshConfig;

    public function __construct(
        Api $api,
        Config $config,
        SshConfig $sshConfig
    )
    {
        $this->api = $api;
        $this->config = $config;
        $this->sshConfig = $sshConfig;
        parent::__construct();
    }

    protected function configure()
    {
        $this->addOption('all', 'a', InputOption::VALUE_NONE, 'Log out from all local sessions')
            ->addOption('other', null, InputOption::VALUE_NONE, 'Log out from other local sessions')
            ->setDescription('Log out of ' . $this->config->get('service.name'));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // API tokens set via the environment or the config file cannot be
        // removed using this command.
        // API tokens set via the auth:api-token-login command will be safely
        // deleted.
        if ($this->api->hasApiToken(false)) {
            $this->stdErr->writeln('<comment>Warning: an API token is set via config</comment>');
        }

        if ($input->getOption('other') && !$input->getOption('all')) {
            $currentSessionId = $this->config->getSessionId();
            $this->stdErr->writeln(sprintf('The current session ID is: <info>%s</info>', $currentSessionId));
            $other = \array_filter($this->api->listSessionIds(), function ($sessionId) use ($currentSessionId) {
                return $sessionId !== $currentSessionId;
            });
            if (empty($other)) {
                $this->stdErr->writeln('No other sessions exist.');
                return 0;
            }
            $this->stdErr->writeln('');
            foreach ($other as $sessionId) {
                $api = new Api($this->config->withOverrides(['api.session_id' => $sessionId]), null, $output);
                $api->logout();
                $this->stdErr->writeln(sprintf('Logged out from session: <info>%s</info>', $sessionId));
            }
            $this->stdErr->writeln('');
            $this->stdErr->writeln('All other sessions have been deleted.');
            return 0;
        }

        $this->api->logout();
        $this->stdErr->writeln('You are now logged out.');

        $this->sshConfig->deleteSessionConfiguration();

        // Check for other sessions.
        if ($input->getOption('all')) {
            $this->api->deleteAllSessions();
            $this->stdErr->writeln('');
            $this->stdErr->writeln('All sessions have been deleted.');
            $this->api->showSessionInfo();
            return 0;
        }

        $this->api->showSessionInfo();

        if ($this->api->anySessionsExist()) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'Other sessions exist. Log out of all sessions with: <comment>%s logout --all</comment>',
                $this->config->get('application.executable')
            ));
        }

        return 0;
    }
}
