<?php

namespace CommerceGuys\Platform\Cli\Command;

use Guzzle\Http\Exception\ClientErrorResponseException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class PlatformLoginCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('login')
            ->setDescription('Log in to Platform.sh');
    }

    public function isLocal()
    {
        return true;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->checkRequirements($output);

        $output->writeln("\nPlease log in using your Platform.sh account\n");
        $this->configureAccount($input, $output);
        $output->writeln("\n<info>Thank you, you are all set.</info>");

        // Run the destructor right away to ensure configuration gets persisted.
        // That way any commands that are executed next in the chain will work.
        $this->__destruct();
    }

    protected function checkRequirements($output)
    {
        if (ini_get('safe_mode')) {
            throw new \Exception('PHP safe_mode must be disabled.');
        }
        $gitVersion = shell_exec('git version');
        if (strpos($gitVersion, 'git version') === false) {
            throw new \Exception('Git must be installed.');
        }
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
            $createAccountText .= 'Would you like to create a new account? [Y/N] ';
            $createAccount = $helper->ask($input, $output, new ConfirmationQuestion($createAccountText, false));
            if ($createAccount) {
                // @todo
            } else {
                // Start from the beginning.
                return $this->configureAccount($output);
            }
        }

        $pendingInvitation = false;
        if ($pendingInvitation) {
            $resendInviteText = "\nThis email address is associated with a Platform.sh account, \n";
            $resendInviteText .= "but you haven't verified your email address yet. \n";
            $resendInviteText .= "Please click on the link in the email we sent you. \n";
            $resendInviteText .= "Do you want us to send you the email again? [Y/N] ";
            $resendInvite = $helper->ask($input, $output, new ConfirmationQuestion($resendInviteText, false));
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
