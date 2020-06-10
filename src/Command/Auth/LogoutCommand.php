<?php
namespace Platformsh\Cli\Command\Auth;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LogoutCommand extends CommandBase
{
    protected $local = true;

    protected function configure()
    {
        $this
            ->setName('auth:logout')
            ->setAliases(['logout'])
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Log out of all sessions')
            ->setDescription('Log out of ' . $this->config()->get('service.name'));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // API tokens set via the environment or the config file cannot be
        // removed using this command.
        // API tokens set via the auth:api-token-login command will be safely
        // deleted.
        if ($this->api()->hasApiToken(false)) {
            $this->stdErr->writeln('<comment>Warning: an API token is set via config</comment>');
        }

        $this->api()->logout();
        $this->stdErr->writeln('You are now logged out.');

        // Delete session SSH configuration.
        /** @var \Platformsh\Cli\Service\SshConfig $sshConfig */
        $sshConfig = $this->getService('ssh_config');
        $sshConfig->deleteSessionConfiguration();

        // Delete session files.
        $dir = $this->config()->getSessionDir(true);
        if (\file_exists($dir)) {
            /** @var \Platformsh\Cli\Service\Filesystem $fs */
            $fs = $this->getService('fs');
            $fs->remove($dir);
        }

        // Check for other sessions.
        if ($input->getOption('all')) {
            $this->api()->deleteAllSessions();
            $this->stdErr->writeln('');
            $this->stdErr->writeln('All sessions have been deleted.');
            $this->showSessionInfo();
            return 0;
        }

        $this->showSessionInfo();

        if ($this->api()->anySessionsExist()) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'Other sessions exist. Log out of all sessions with: <comment>%s logout --all</comment>',
                $this->config()->get('application.executable')
            ));
        }

        return 0;
    }
}
