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
            ->setDescription('Log out of ' . self::$config->get('service.name'));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Ignore API tokens for this command.
        if ($this->api()->hasApiToken()) {
            $this->stdErr->writeln('<comment>Warning: an API token is set</comment>');
        }

        if (!$this->api()->isLoggedIn() && !$input->getOption('all')) {
            $this->stdErr->writeln(
                "You are not currently logged in"
            );

            return 0;
        }

        // Ask for a confirmation.
        if ($this->api()->isLoggedIn() && !$this->getHelper('question')->confirm("Are you sure you wish to log out?")) {
            $this->stdErr->writeln('You remain logged in.');

            return 1;
        }

        $this->api()->getClient(false)
             ->getConnector()
             ->logOut();
        $this->api()->clearCache();
        $this->stdErr->writeln('You are now logged out.');


        $sessionsDir = $this->getSessionsDir();
        if ($input->getOption('all')) {
            if (is_dir($sessionsDir)) {
                /** @var \Platformsh\Cli\Helper\FilesystemHelper $fs */
                $fs = $this->getHelper('fs');
                $fs->remove($sessionsDir);
                $this->stdErr->writeln('All session files have been deleted.');
            }
        }
        elseif (is_dir($sessionsDir) && glob($sessionsDir . '/sess-cli-*', GLOB_NOSORT)) {
            $this->stdErr->writeln('Other session files exist. Delete them with: <comment>' . self::$config->get('application.executable') . ' logout --all</comment>');
        }

        return 0;
    }
}
