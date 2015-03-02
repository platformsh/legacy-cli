<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PlatformLogoutCommand extends PlatformCommand
{

    protected $removeConfigFile;

    protected function configure()
    {
        $this
          ->setName('logout')
          ->addOption('all', null, InputOption::VALUE_NONE, 'Log out of all sessions')
          ->setDescription('Log out of Platform.sh');
    }

    public function isLocal()
    {
        return true;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // We manually check for the configuration file here. If it does not
        // exist then this command should not run.
        $configPath = $this->getHelper('fs')
                           ->getHomeDirectory() . '/.platform';
        $configFileExists = file_exists($configPath);

        if (!$configFileExists) {
            // There is no configuration!
            $output->writeln(
              "<comment>You are not currently logged in to the Platform.sh CLI and, consequently, are unable to log out.</comment>"
            );

            return 1;
        }
        // Ask for a confirmation.
        $confirm = $this->getHelper('question')
                        ->confirm(
                          "<comment>This command will remove your current Platform.sh configuration.\nYou will have to re-enter your Platform.sh credentials to use the CLI.</comment>\nAre you sure you wish to continue?",
                          $input,
                          $output,
                          false
                        );

        if (!$confirm) {
            $output->writeln(
              "<info>Okay! You remain logged in to the Platform.sh CLI with your current credentials.</info>"
            );

            return 1;
        }

        $this->getClient(false)
             ->getConnector()
             ->logOut();
        $this->clearCache();
        $output->writeln(
          "<comment>Your Platform.sh configuration file has been removed and you have been logged out of the Platform CLI.</comment>"
        );

        if ($input->getOption('all')) {
            /** @var \Platformsh\Cli\Helper\FilesystemHelper $fs */
            $fs = $this->getHelper('fs');
            $fs->remove($fs->getHomeDirectory() . '/.platformsh/.session');
            $output->writeln("All known session files have been deleted");
        }

        return 0;
    }
}
