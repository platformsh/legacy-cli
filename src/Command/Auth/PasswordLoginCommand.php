<?php
namespace Platformsh\Cli\Command\Auth;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class PasswordLoginCommand extends CommandBase
{
    protected $stability = 'deprecated';
    protected $hiddenInList = true;

    protected function configure()
    {
        $service = $this->config()->get('service.name');
        $accountsUrl = $this->config()->get('service.accounts_url');
        $executable = $this->config()->get('application.executable');

        $this->setName('auth:password-login');
        if ($this->config()->get('application.login_method') === 'password') {
            $this->setAliases(['login']);
        }

        $this->setHiddenAliases(['auth:login']);
        $this->setDescription('Log in to ' . $service . ' using a username and password');

        $help = 'Use this command to log in to your ' . $service . ' account in the terminal.'
            . "\n\nYou can create an account at:\n    <info>" . $accountsUrl . '</info>'
            . "\n\nIf you have an account, but you do not already have a password, you can set one here:\n    <info>"
            . $accountsUrl . '/user/password</info>'
            . "\n\nAlternatively, to log in to the CLI with a browser, run:\n    <info>"
            . $executable . ' auth:browser-login</info>'
            . "\n\n" . $this->getApiTokenHelp();
        $this->setHelp($help);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($this->api()->hasApiToken()) {
            $this->stdErr->writeln('Cannot log in: an API token is set');
            return 1;
        }
        if (!$input->isInteractive()) {
            $this->stdErr->writeln('Non-interactive login is not supported.');
            $this->stdErr->writeln("\n" . $this->getApiTokenHelp('comment'));
            return 1;
        }

        $this->stdErr->writeln(
            'Please log in using your <info>' . $this->config()->get('service.name') . '</info> account.'
        );

        $this->stdErr->writeln('');
        $this->stdErr->writeln('<fg=yellow;options=bold,reverse>Warning: </><fg=yellow;options=reverse>This command is deprecated.</>');
        $this->stdErr->writeln(sprintf('Password login will soon be removed in the %s API.', $this->config()->get('service.name')));
        $this->stdErr->writeln('');

        $this->configureAccount($input, $this->stdErr);

        /** @var \Doctrine\Common\Cache\CacheProvider $cache */
        $cache = $this->getService('cache');
        $cache->flushAll();

        $this->finalizeLogin();
        return 0;
    }

    protected function configureAccount(InputInterface $input, OutputInterface $output)
    {
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        $question = new Question('Your email address or username: ');
        $question->setValidator([$this, 'validateUsernameOrEmail']);
        $question->setMaxAttempts(5);
        $email = $questionHelper->ask($input, $output, $question);

        $question = new Question('Your password: ');
        $question->setValidator(
            function ($answer) {
                if (trim($answer) == '') {
                    throw new \RuntimeException('The password cannot be empty');
                }

                return $answer;
            }
        );
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $question->setMaxAttempts(5);
        $password = $questionHelper->ask($input, $output, $question);

        try {
            $this->api()->getClient(false)
                ->getConnector()
                ->logIn($email, $password, true);
        } catch (BadResponseException $e) {
            // If a two-factor authentication challenge is received, then ask
            // the user for their TOTP code, and then retry logging in.
            if ($e->getResponse()->getHeader('X-Drupal-TFA')) {
                $question = new Question("Your application verification code: ");
                $question->setValidator(function ($answer) use ($email, $password) {
                    if (trim($answer) == '') {
                        throw new \RuntimeException("The code cannot be empty.");
                    }
                    try {
                        $this->api()->getClient(false)
                            ->getConnector()
                            ->logIn($email, $password, true, $answer);
                    } catch (BadResponseException $e) {
                        // If there is a two-factor authentication error, show
                        // the error description that the server provides.
                        //
                        // A RuntimeException here causes the user to be asked
                        // again for their TOTP code.
                        if ($e->getResponse()->getHeader('X-Drupal-TFA')) {
                            $json = $e->getResponse()->json();
                            throw new \RuntimeException($json['error_description']);
                        } else {
                            throw $e;
                        }
                    }

                    return $answer;
                });
                $question->setMaxAttempts(5);
                $output->writeln("\nTwo-factor authentication is required.");
                $questionHelper->ask($input, $output, $question);
            } elseif ($e->getResponse()->getStatusCode() === 401) {
                $output->writeln([
                    '',
                    '<error>Login failed. Please check your credentials.</error>',
                    '',
                    "Forgot your password? Or don't have a password yet? Visit:",
                    '  <comment>' . $this->config()->get('service.accounts_url') . '/user/password</comment>',
                    '',
                ]);
                $this->configureAccount($input, $output);
            } else {
                throw $e;
            }
        }
    }

    /**
     * Validation callback for the username or email address.
     *
     * @param string $username
     *
     * @return string
     */
    public function validateUsernameOrEmail($username)
    {
        $username = trim($username);
        if (!strlen($username) || (!filter_var($username, FILTER_VALIDATE_EMAIL) && !$this->validateUsername($username))) {
            throw new \RuntimeException(
                'Please enter a valid email address or username.'
            );
        }

        return $username;
    }

    /**
     * Validate a username.
     *
     * @param string $username
     *
     * @return bool
     */
    protected function validateUsername($username)
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{0,30}[a-z0-9]$/', $username) === 1;
    }
}
