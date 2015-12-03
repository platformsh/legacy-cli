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
          ->setName('logout')
          ->addOption('all', null, InputOption::VALUE_NONE, 'Log out of all sessions')
          ->setDescription('Log out of Platform.sh');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Ignore API tokens for this command.
        if (isset(self::$apiToken)) {
            self::$apiToken = null;
            $this->getClient(false)->getConnector()->setApiToken('');
            $this->stdErr->writeln('<comment>Warning: an API token is set</comment>');
        }

        if (!$this->isLoggedIn() && !$input->getOption('all')) {
            $this->stdErr->writeln(
              "You are not currently logged in to the Platform.sh CLI"
            );

            return 0;
        }

        // Ask for a confirmation.
        $confirm = $this->getHelper('question')
          ->confirm("Are you sure you wish to log out?", $input, $this->stdErr);

        if (!$confirm) {
            $this->stdErr->writeln("You remain logged in to the Platform.sh CLI.");

            return 1;
        }

        $this->getClient(false)
             ->getConnector()
             ->logOut();
        $this->clearCache();
        $this->stdErr->writeln("You have been logged out of the Platform.sh CLI.");

        if ($input->getOption('all')) {
            if (file_exists($this->getSessionsDir())) {
                /** @var \Platformsh\Cli\Helper\FilesystemHelper $fs */
                $fs = $this->getHelper('fs');
                $fs->remove($this->getSessionsDir());
            }
            $this->stdErr->writeln("All known session files have been deleted.");
        }

        return 0;
    }
}
