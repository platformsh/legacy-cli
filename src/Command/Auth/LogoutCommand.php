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

        // Delete certificate files and configuration.
        /** @var \Platformsh\Cli\SshCert\Certifier $certifier */
        $certifier = $this->getService('certifier');
        $certifier->deleteConfiguration();

        // Check for other sessions.
        if ($input->getOption('all')) {
            $this->api()->deleteAllSessions();
            $this->stdErr->writeln('All sessions have been deleted.');
        } elseif ($this->api()->anySessionsExist()) {
            $this->stdErr->writeln(sprintf(
                'Other sessions exist. Log out of all sessions with: <comment>%s logout --all</comment>',
                $this->config()->get('application.executable')
            ));
        }

        return 0;
    }
}
