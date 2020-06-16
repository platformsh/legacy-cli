<?php
namespace Platformsh\Cli\Command\User;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserDeleteCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('user:delete')
            ->setDescription('Delete a user from the project')
            ->addArgument('email', InputArgument::REQUIRED, "The user's email address");
        $this->addProjectOption()->addWaitOptions();
        $this->addExample('Delete Alice from the project', 'alice@example.com');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $project = $this->getSelectedProject();

        $email = $input->getArgument('email');
        $selectedUser = $this->api()->loadProjectAccessByEmail($project, $email);
        if (empty($selectedUser)) {
            $this->stdErr->writeln("User not found: <error>$email</error>");
            return 1;
        }

        if ($project->owner === $selectedUser->id) {
            $this->stdErr->writeln(sprintf(
                'The user <error>%s</error> is the owner of the project %s.',
                $email,
                $this->api()->getProjectLabel($project, 'error')
            ));
            $this->stdErr->writeln("The project's owner cannot be deleted.");
            return 1;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        if (!$questionHelper->confirm("Are you sure you want to delete the user <info>$email</info>?")) {
            return 1;
        }

        $result = $selectedUser->delete();

        $this->stdErr->writeln("User <info>$email</info> deleted");

        if (!$result->getActivities()) {
            $this->redeployWarning();
        } elseif ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $activityMonitor->waitMultiple($result->getActivities(), $project);
        }

        // If the user was deleting themselves from the project, then invalidate
        // the projects cache.
        $myUserId = $this->api()->getMyAccount()['id'];
        if ($myUserId === $selectedUser->id) {
            $this->api()->clearProjectsCache();
        }

        return 0;
    }
}
