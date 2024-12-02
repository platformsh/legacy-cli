<?php
namespace Platformsh\Cli\Command\User;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Client\Model\UserAccess\ProjectUserAccess;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'user:delete', description: 'Delete a user from the project')]
class UserDeleteCommand extends UserCommandBase
{

    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Api $api, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure()
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, "The user's email address");
        $this->selector->addProjectOption($this->getDefinition())->addWaitOptions();
        $this->addExample('Delete Alice from the project', 'alice@example.com');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);
        $project = $selection->getProject();
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
                $this->api->getProjectLabel($project, 'error')
            ));
            $this->stdErr->writeln("The project's owner cannot be deleted.");
            return 1;
        }

        $questionHelper = $this->questionHelper;

        if (!$questionHelper->confirm("Are you sure you want to delete the user <info>$email</info>?")) {
            return 1;
        }

        $result = $selection->delete();

        $this->stdErr->writeln("User <info>$email</info> deleted");

        if ($result->getActivities() && $this->shouldWait($input)) {
            $activityMonitor = $this->activityMonitor;
            $activityMonitor->waitMultiple($result->getActivities(), $project);
        } elseif (!$this->centralizedPermissionsEnabled()) {
            $this->api->redeployWarning();
        }

        // If the user was deleting themselves from the project, then invalidate
        // the projects cache.
        if ($this->api->getMyUserId() === $userId) {
            $this->api->clearProjectsCache();
        }

        return 0;
    }
}
