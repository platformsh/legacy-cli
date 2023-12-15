<?php
namespace Platformsh\Cli\Command\User;

use Platformsh\Client\Model\UserAccess\ProjectUserAccess;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserDeleteCommand extends UserCommandBase
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

        $selection = $this->loadProjectUser($project, $email);
        if (!$selection) {
            $this->stdErr->writeln("User not found: <error>$email</error>");
            return 1;
        }
        $userId = $selection instanceof ProjectUserAccess ? $selection->user_id : $selection->id;
        $email = $selection instanceof ProjectUserAccess ? $selection->getUserInfo()->email : $this->legacyUserInfo($selection)['email'];

        if ($project->owner === $userId) {
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

        $result = $selection->delete();

        $this->stdErr->writeln("User <info>$email</info> deleted");

        if ($result->getActivities() && $this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $activityMonitor->waitMultiple($result->getActivities(), $project);
        } elseif (!$this->centralizedPermissionsEnabled()) {
            $this->redeployWarning();
        }

        // If the user was deleting themselves from the project, then invalidate
        // the projects cache.
        if ($this->api()->getMyUserId() === $userId) {
            $this->api()->clearProjectsCache();
        }

        return 0;
    }
}
