<?php

namespace CommerceGuys\Platform\Cli\Command;

use Guzzle\Http\ClientInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Dumper;

class LoginCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('login')
            ->setDescription('Login to platform');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->checkRequirements($output) || $this->hasConfiguration()) {
            return;
        }

        $output->writeln("Welcome to Commerce Platform! \n");
        $this->configureAccount($output);

        $message = '<info>';
        $message = "\nThank you, you are all set. Here is what you can do now: \n";
        $message .= "- You can clone any of the projects you have access to: \n\n";
        $message .= "\t platform get commerceguys # drupalcommerce.org \n";
        $message .= "\t platform get drupalcommerce # commerceguys.com \n\n";
        $message .= "- You can also create a new project: \n \n";
        $message .= "\t platform create drupal myproject1 \n";
        $message .= "\t platform create drupal:kickstart myproject2\n";
        $message .= "</info>";
        $output->writeln($message);
    }

    protected function checkRequirements($output)
    {
        $status = true;
        if (ini_get('safe_mode')) {
            $output->writeln('<error>PHP safe_mode must be disabled.</error>');
            $status = false;
        }
        if (!shell_exec('which git')) {
            $output->writeln('<error>Git must be installed.</error>');
            $status = false;
        }
        if (!shell_exec('which drush')) {
            $output->writeln('<error>Drush must be installed.</error>');
            $status = false;
        }

        return $status;
    }

    protected function configureAccount($output)
    {
        $dialog = $this->getHelperSet()->get('dialog');
        $emailValidator = function ($data) {
            if (empty($data) || !filter_var($data, FILTER_VALIDATE_EMAIL)) {
                throw new \RunTimeException(
                    'Please provide a valid email address.'
                );
            }
            return $data;
        };
        $email = $dialog->askAndValidate($output, 'Your email address: ', $emailValidator);

        $userExists = true;
        if (!$userExists) {
            $createAccountText = "\nThis email address is not associated to a Platform account. \n";
            $createAccountText .= 'Would you like to create a new account? [Y/N] ';
            $createAccount = $dialog->askConfirmation($output, $createAccountText, false);
            if ($createAccount) {
                // @todo
            }
            else {
                // Start from the beginning.
                return $this->configureAccount($output);
            }
        }

        $pendingInvitation = false;
        if ($pendingInvitation) {
            $resendInviteText = "\nThis email address is associated with a Platform account, \n";
            $resendInviteText .= "but you haven't verified your email address yet. \n";
            $resendInviteText .= "Please click on the link in the email we sent you. \n";
            $resendInviteText .= "Do you want us to send you the email again? [Y/N] ";
            $resendInvite = $dialog->askConfirmation($output, $resendInviteText, false);
            if ($resendInvite) {
                // @todo
            }

            return;
        }

        $password = $dialog->askHiddenResponse($output, "\nYour password: ");
        $this->authenticateUser($email, $password);
    }

    protected function hasConfiguration()
    {
        $homeDir = shell_exec('cd ~ && pwd');
        return file_exists($homeDir . '/.platform');
    }

}
