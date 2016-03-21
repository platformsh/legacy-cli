<?php
namespace Platformsh\Cli\Command\Auth;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class LoginCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('login')
            ->setDescription('Log in to ' . CLI_CLOUD_SERVICE);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Disable the API token for this command.
        if (isset(self::$apiToken)) {
            throw new \Exception('Cannot log in: an API token is set');
        }
        // Login can only happen during interactive use.
        if (!$input->isInteractive()) {
            throw new \Exception('Non-interactive login not supported');
        }

        $this->stdErr->writeln('Please log in using your <info>' . CLI_CLOUD_SERVICE . '</info> account.');
        $this->stdErr->writeln('');
        $this->configureAccount($input, $this->stdErr);

        $this->clearCache();

        $info = $this->getClient(false)->getAccountInfo();
        if (isset($info['mail'])) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('You are logged in as <info>' . $info['mail'] . '</info>.');
        }
    }

    protected function configureAccount(InputInterface $input, OutputInterface $output)
    {
        /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $helper */
        $helper = $this->getHelper('question');

        $question = new Question('Your email address: ');
        $question->setValidator(
            function ($answer) {
                if (empty($answer) || !filter_var($answer, FILTER_VALIDATE_EMAIL)) {
                    throw new \RunTimeException(
                        'Please provide a valid email address.'
                    );
                }

                return $answer;
            }
        );
        $question->setMaxAttempts(5);
        $email = $helper->ask($input, $output, $question);

        $pendingInvitation = false;
        if ($pendingInvitation) {
            $resendInviteText = "\nThis email address is associated with a " . CLI_CLOUD_SERVICE . " account, \n";
            $resendInviteText .= "but you haven't verified your email address yet. \n";
            $resendInviteText .= "Please click on the link in the email we sent you. \n";
            $resendInviteText .= "Do you want us to send you the email again?";
            $resendInvite = $helper->confirm($resendInviteText, $input, $output, false);
            if ($resendInvite) {
                // @todo
            }

            return;
        }

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
        $password = $helper->ask($input, $output, $question);

        try {
            $this->authenticateUser($email, $password);
        } catch (BadResponseException $e) {
            // If a two-factor authentication challenge is received, then ask
            // the user for their TOTP code, and then retry authenticateUser().
            if ($e->getResponse()->getHeader('X-Drupal-TFA')) {
                $question = new Question("Your application verification code: ");
                $question->setValidator(function ($answer) use ($email, $password) {
                    if (trim($answer) == '') {
                        throw new \RuntimeException("The code cannot be empty.");
                    }
                    try {
                        $this->authenticateUser($email, $password, $answer);
                    }
                    catch (BadResponseException $e) {
                        // If there is a two-factor authentication error, show
                        // the error description that the server provides.
                        //
                        // A RuntimeException here causes the user to be asked
                        // again for their TOTP code.
                        if ($e->getResponse()->getHeader('X-Drupal-TFA')) {
                            $json = $e->getResponse()->json();
                            throw new \RuntimeException($json['error_description']);
                        }
                        else {
                            throw $e;
                        }
                    }

                    return $answer;
                });
                $question->setMaxAttempts(5);
                $output->writeln("\nTwo-factor authentication is required.");
                $helper->ask($input, $output, $question);
            }
            elseif ($e->getResponse()->getStatusCode() === 401) {
                $output->writeln("\n<error>Login failed. Please check your credentials.</error>\n");
                $output->writeln("Forgot your password? Or don't have a password yet? Visit:");
                $output->writeln("  <comment>" . CLI_SERVICE_ACCOUNTS_URL . "/user/password</comment>\n");
                $this->configureAccount($input, $output);
            }
            else {
                throw $e;
            }
        }
    }

}
