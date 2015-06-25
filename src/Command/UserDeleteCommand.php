<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserDeleteCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('user:delete')
          ->setDescription('Delete a user')
          ->addArgument('email', InputArgument::REQUIRED, "The user's email address");
        $this->addProjectOption();
        $this->addExample('Delete Alice from the project', 'alice@example.com');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $project = $this->getSelectedProject();

        $email = $input->getArgument('email');
        foreach ($project->getUsers() as $user) {
            $account = $user->getAccount();
            if ($account['email'] === $email) {
                $selectedUser = $user;
                break;
            }
        }
        if (empty($selectedUser)) {
            $this->stdErr->writeln("User not found: <error>$email</error>");
            return 1;
        }

        /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');

        if (!$questionHelper->confirm("Are you sure you want to delete the user <info>$email</info>?", $input, $this->stdErr)) {
            return 1;
        }

        $selectedUser->delete();

        $this->stdErr->writeln("User <info>$email</info> deleted");
        return 0;
    }

}
