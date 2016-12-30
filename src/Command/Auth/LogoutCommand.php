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
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if ($this->api()->isLoggedIn()
            && !$questionHelper->confirm("Are you sure you wish to log out?")) {
            $this->stdErr->writeln('You remain logged in.');

            return 1;
        }

        $this->api()->getClient(false)
             ->getConnector()
             ->logOut();
        /** @var \Doctrine\Common\Cache\CacheProvider $cache */
        $cache = $this->getService('cache');
        $cache->flushAll();
        $this->stdErr->writeln('You are now logged out.');

        $config = $this->config();
        $sessionsDir = $config->getUserConfigDir() . '/.session';
        if ($input->getOption('all')) {
            if (is_dir($sessionsDir)) {
                /** @var \Platformsh\Cli\Service\Filesystem $fs */
                $fs = $this->getService('fs');
                $fs->remove($sessionsDir);
                $this->stdErr->writeln('All session files have been deleted.');
            }
        } elseif (is_dir($sessionsDir) && glob($sessionsDir . '/sess-cli-*', GLOB_NOSORT)) {
            $this->stdErr->writeln(sprintf(
                'Other session files exist. Delete them with: <comment>%s logout --all</comment>',
                $config->get('application.executable')
            ));
        }

        return 0;
    }
}
