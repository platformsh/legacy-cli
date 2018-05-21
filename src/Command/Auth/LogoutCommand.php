<?php
namespace Platformsh\Cli\Command\Auth;

use Doctrine\Common\Cache\CacheProvider;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Filesystem;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LogoutCommand extends CommandBase
{
    protected $local = true;

    protected static $defaultName = 'auth:logout';

    private $api;
    private $cache;
    private $config;
    private $filesystem;

    public function __construct(
        Api $api,
        CacheProvider $cache,
        Config $config,
        Filesystem $filesystem
    )
    {
        $this->api = $api;
        $this->cache = $cache;
        $this->config = $config;
        $this->filesystem = $filesystem;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['logout'])
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Log out of all sessions')
            ->setDescription('Log out of ' . $this->config()->get('service.name'));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Ignore API tokens for this command.
        if ($this->api->hasApiToken()) {
            $this->stdErr->writeln('<comment>Warning: an API token is set</comment>');
        }

        if (!$this->api->isLoggedIn() && !$input->getOption('all')) {
            $this->stdErr->writeln(
                "You are not currently logged in"
            );

            return 0;
        }

        // Ask for a confirmation.
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if ($this->api->isLoggedIn()
            && !$questionHelper->confirm("Are you sure you wish to log out?")) {
            $this->stdErr->writeln('You remain logged in.');

            return 1;
        }

        $this->api->getClient(false)
             ->getConnector()
             ->logOut();
        $this->cache->flushAll();
        $this->stdErr->writeln('You are now logged out.');

        $sessionsDir = $this->config->getWritableUserDir() . '/.session';
        if ($input->getOption('all')) {
            $this->api->deleteFromKeychain();
            if (is_dir($sessionsDir)) {
                $this->filesystem->remove($sessionsDir);
                $this->stdErr->writeln('All session files have been deleted.');
            }
        } elseif (is_dir($sessionsDir) && glob($sessionsDir . '/sess-cli-*', GLOB_NOSORT)) {
            $this->stdErr->writeln(sprintf(
                'Other session files exist. Delete them with: <comment>%s logout --all</comment>',
                $this->config->get('application.executable')
            ));
        }

        return 0;
    }
}
