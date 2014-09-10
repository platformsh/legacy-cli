<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class PlatformLogoutCommand extends PlatformCommand
{

    protected $removeConfigFile;

    protected function configure()
    {
        $this
            ->setName('logout')
            ->setAliases(array('logout'))
            ->setDescription('Log out of the Platform CLI');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // We manually check for the configuration file here. If it does not
        // exist then this command should not run.
        $application = $this->getApplication();
        $configPath = $application->getHomeDirectory() . '/.platform';
        $configFileExists = file_exists($configPath);

        if (!$configFileExists) {
            // There is no configuration!
            $output->writeln("<comment>You are not currently logged in to the Platform CLI and, consequently, are unable to log out.</comment>");
            return;
        }
        // Ask for a confirmation.
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion("<comment>This command will remove your current Platform configuration.\n\nYou will have to re-enter your Platform credentials to use the CLI tool. You can login to another account using the login command, or switch to another account using the switch command</comment> <question>Are you sure you wish to continue? (y/n):</question> ", false);

        if (!$helper->ask($input, $output, $question)) {
            $output->writeln("<info>Okay! You remain logged in to the Platform CLI with your current credentials.</info>");
            return;
        }
        else {
            try {
                $this->removeConfigFile = TRUE;
                $this->deleteConfigs();
                $output->writeln("<comment>Your Platform configuration files has been removed and you have been logged out of the Platform CLI.</comment>");
            }
            catch (\Exception $e) {
                // @todo: Real exception handling here.
            }
            return;
        }
    }

    public static function skipLogin()
    {
        return TRUE;
    }

    /**
     * Destructor: Override the destructor to nuke the config.
     */
    public function __destruct()
    {
        if ($this->removeConfigFile) {
            // Do nothing, especially not trying to commit a non-existent
            // config to a non-existent file. That would be dumb.
        }
        else {
            parent::__destruct();
        }
    }
}