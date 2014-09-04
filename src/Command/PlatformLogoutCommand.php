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
            ->setName('platform:logout')
            ->setAliases(array('logout'))
            ->setDescription('Log out of Platform');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion('<question>This command will remove your current Platform configuration. You will have to re-enter your Platform credentials to use the CLI tool. Are you sure you wish to continue? </question>', false);

        if (!$helper->ask($input, $output, $question)) {
            return;
        }
        else {
            try {
                $this->removeConfigFile = TRUE;
                $application = $this->getApplication();
                $configPath = $application->getHomeDirectory() . '/.platform';
                unlink($configPath);
                $output->writeln("<comment>Your Platform configuration file has been removed and you have been logged out of the Platform CLI.</comment>");
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