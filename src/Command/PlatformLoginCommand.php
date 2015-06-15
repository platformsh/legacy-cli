<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class PlatformLoginCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('login')
          ->setDescription('Log in to Platform.sh');
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

        $this->stdErr->writeln("Please log in using your <info>Platform.sh</info> account\n");
        $this->configureAccount($input, $this->stdErr);
        $this->stdErr->writeln("\n<info>Thank you, you are all set.</info>\n");
    }

    protected function configureAccount(InputInterface $input, OutputInterface $output)
    {
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

        $userExists = true;
        if (!$userExists) {
            $createAccountText = "\nThis email address is not associated with a Platform.sh account. \n";
            $createAccountText .= 'Would you like to create a new account?';
            $createAccount = $helper->confirm($createAccountText, $input, $output);
            if ($createAccount) {
                // @todo
            } else {
                // Start from the beginning.
                $this->configureAccount($input, $output);

                return;
            }
        }

        $pendingInvitation = false;
        if ($pendingInvitation) {
            $resendInviteText = "\nThis email address is associated with a Platform.sh account, \n";
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
        } catch (\InvalidArgumentException $e) {
            $output->writeln("\n<error>Login failed. Please check your credentials.</error>\n");
            $output->writeln("Forgot your password? Visit: <comment>https://marketplace.commerceguys.com/user/password</comment>\n");
            $this->configureAccount($input, $output);
        }
    }

}
