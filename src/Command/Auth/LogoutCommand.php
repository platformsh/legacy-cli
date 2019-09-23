<?php
namespace Platformsh\Cli\Command\Auth;

use Platformsh\Cli\ApiToken\StorageInterface;
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
        if ($this->api()->hasApiToken(false)) {
            $this->stdErr->writeln('<comment>Warning: an API token is set via config</comment>');
        }

        // Delete stored API token(s).
        /** @var StorageInterface $storage */
        $storage = $this->getService('api_token_storage');
        $storage->deleteToken();

        // Log out.
        $this->api()->getClient(false)
             ->getConnector()
             ->logOut();

        // Clear the cache.
        /** @var \Doctrine\Common\Cache\CacheProvider $cache */
        $cache = $this->getService('cache');
        $cache->flushAll();

        $this->stdErr->writeln('You are now logged out.');

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
