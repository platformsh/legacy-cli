<?php
namespace Platformsh\Cli\Command\Auth;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class LoginCommand extends CommandBase
{

    protected function configure()
    {
        $service = $this->config()->get('service.name');
        $accountsUrl = $this->config()->get('service.accounts_url');
        $this
            ->setName('auth:login')
            ->setAliases(['login'])
            ->setDescription('Log in to ' . $service);
        $help = 'Use this command to log in to your ' . $service . ' account.'
            . "\n\nYou can create an account at:\n    <info>" . $accountsUrl . '</info>'
            . "\n\nIf you have an account, but you do not already have a password, you can set one here:\n    <info>"
            . $accountsUrl . '/user/password</info>';
        $this->setHelp($help);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Disable the API token for this command.
        if ($this->api()->hasApiToken()) {
            throw new \Exception('Cannot log in: an API token is set');
        }
        // Login can only happen during interactive use.
        if (!$input->isInteractive()) {
            throw new RuntimeException('Non-interactive login not supported');
        }

        $this->stdErr->writeln(
            'Please log in using your <info>' . $this->config()->get('service.name') . '</info> account.'
        );
        $this->stdErr->writeln('');
        $this->configureAccount($input, $this->stdErr);

        /** @var \Doctrine\Common\Cache\CacheProvider $cache */
        $cache = $this->getService('cache');
        $cache->flushAll();

        $info = $this->api()->getClient(false)->getAccountInfo();
        if (isset($info['mail'])) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('You are logged in as <info>' . $info['mail'] . '</info>.');
        }
    }

    protected function configureAccount(InputInterface $input, OutputInterface $output)
    {
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        $question = new Question('Your email address: ');
        $question->setValidator(
            function ($answer) {
                if (empty($answer) || !filter_var($answer, FILTER_VALIDATE_EMAIL)) {
                    throw new \RuntimeException(
                        'Please provide a valid email address.'
                    );
                }

                return $answer;
            }
        );
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
}
