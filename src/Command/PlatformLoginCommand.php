<?php

namespace CommerceGuys\Platform\Cli\Command;

use Guzzle\Http\Exception\ClientErrorResponseException;
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
        $this->checkRequirements();

        if (!$input->isInteractive()) {
            throw new \Exception('Non-interactive login not supported');
        }

        $output->writeln("\nPlease log in using your Platform.sh account\n");
        $this->configureAccount($input, $output);
        $output->writeln("\n<info>Thank you, you are all set.</info>");

        // Run the destructor right away to ensure configuration gets persisted.
        // That way any commands that are executed next in the chain will work.
        $this->__destruct();
    }

    protected function checkRequirements()
    {
        if (ini_get('safe_mode')) {
            throw new \Exception('PHP safe_mode must be disabled.');
        }
        $this->getHelper('git')->ensureInstalled();
    }

    protected function configureAccount(InputInterface $input, OutputInterface $output)
    {
        $helper = $this->getHelper('question');

        $question = new Question('Your email address: ');
        $question->setValidator(function ($answer) {
            if (empty($answer) || !filter_var($answer, FILTER_VALIDATE_EMAIL)) {
              throw new \RunTimeException(
                'Please provide a valid email address.'
              );
            }
            return $answer;
        });
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
        $question->setValidator(function ($answer) {
            if (trim($answer) == '') {
                throw new \RuntimeException('The password cannot be empty');
            }
            return $answer;
        });
        $question->setHidden(true);
        $question->setMaxAttempts(5);
        $password = $helper->ask($input, $output, $question);

        try {
            $this->authenticateUser($email, $password);
        } catch (ClientErrorResponseException $e) {
            $output->writeln("\n<error>Login failed. Please check your credentials.</error>\n");
            $this->configureAccount($input, $output);
        }
    }

}
