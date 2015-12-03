<?php
namespace Platformsh\Cli\Command\User;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\ActivityUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserDeleteCommand extends CommandBase
{

    protected function configure()
    {
        $this
          ->setName('user:delete')
          ->setDescription('Delete a user')
          ->addArgument('email', InputArgument::REQUIRED, "The user's email address");
        $this->addProjectOption()->addNoWaitOption();
        $this->addExample('Delete Alice from the project', 'alice@example.com');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $project = $this->getSelectedProject();

        $email = $input->getArgument('email');
        foreach ($project->getUsers() as $user) {
            if ($this->getAccount($user)['email'] === $email) {
                $selectedUser = $user;
                break;
            }
        }
        if (empty($selectedUser)) {
            $this->stdErr->writeln("User not found: <error>$email</error>");
            return 1;
        }

        if ($project->owner === $selectedUser->id) {
            $this->stdErr->writeln("The user <error>$email</error> is the owner of the project <error>{$project->title}</error>.");
            $this->stdErr->writeln("The project's owner cannot be deleted.");
            return 1;
        }

        /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');

        if (!$questionHelper->confirm("Are you sure you want to delete the user <info>$email</info>?", $input, $this->stdErr)) {
            return 1;
        }

        $result = $selectedUser->delete();

        $this->stdErr->writeln("User <info>$email</info> deleted");

        if (!$input->getOption('no-wait')) {
            ActivityUtil::waitMultiple($result->getActivities(), $this->stdErr);
        }

        // If the user was deleting themselves from the project, then invalidate
        // the projects cache.
        $account = $this->getClient()->getAccountInfo();
        if ($account['uuid'] === $selectedUser->id) {
            $this->clearProjectsCache();
        }

        return 0;
    }

}
