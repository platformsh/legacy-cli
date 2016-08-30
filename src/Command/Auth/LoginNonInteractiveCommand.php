<?php
namespace Platformsh\Cli\Command\Auth;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class LoginNonInteractiveCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('nilogin')
            ->setDescription('Non-interactive log in to ' . self::$config->get('service.name'));
        $help = 'Use this command to log in to your ' . self::$config->get('service.name') . ' account in a non-interactive way.'
            . "\n\nYou can create an account at:\n    <info>" . self::$config->get('service.accounts_url') . '</info>'
            . "\n\nIf you have an account, but you do not already have a password, you can set one here:\n    <info>" . self::$config->get('service.accounts_url') . '/user/password</info>'
            . "\n\nNon-interactive login is not supported if two-factor authentication is enabled.";
        $this->setHelp($help);
        $this->addOption('username', null, InputOption::VALUE_REQUIRED, 'Username (e-mail).');
        $this->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Disable the API token for this command.
        if ($this->api()->hasApiToken()) {
            throw new \Exception('Cannot log in: an API token is set');
        }
        $this->configureAccount($input, $this->stdErr);

        $this->api()->clearCache();

        $info = $this->api()->getClient(false)->getAccountInfo();
        if (isset($info['mail'])) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('You are logged in as <info>' . $info['mail'] . '</info>.');
        }
    }

    protected function configureAccount(InputInterface $input, OutputInterface $output)
    {
        /** @var \Platformsh\Cli\Helper\QuestionHelper $helper */
        try {
            $this->api()->getClient(false)
                ->getConnector()
                ->logIn($input->getOption('username'), $input->getOption('password'), false);
        } catch (BadResponseException $e) {
            // A two-factor authentication challenge is received.
            if ($e->getResponse()->getHeader('X-Drupal-TFA')) {
                $output->writeln("\n<error>Two-factor authentication is required (not supported).</error>");
            }
            elseif ($e->getResponse()->getStatusCode() === 401) {
                $output->writeln("\n<error>Login failed. Please check your credentials.</error>\n");
            }
            else {
                throw $e;
            }
        }
    }

}
