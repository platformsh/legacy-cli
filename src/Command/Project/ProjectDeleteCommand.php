<?php
namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectDeleteCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('project:delete')
            ->setDescription('Delete a project');
        $this->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $project = $this->getSelectedProject();

        if ($this->api()->getMyAccount()['uuid'] !== $project->owner) {
            $this->stdErr->writeln("Only the project's owner can delete it.");
            return 1;
        }

        /** @var \Platformsh\Cli\Helper\QuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');

        $confirmQuestionLines = [
            'You are about to delete the project:',
            '  ' . $this->api()->getProjectLabel($project, 'comment'),
            '',
            ' * This action is <options=bold>irreversible</>.',
            ' * Your site will no longer be accessible.',
            ' * All data associated with this project will be deleted, including backups.',
            ' * You will be charged at the end of the month for any remaining project costs.',
            '',
            'Are you sure you want to delete this project?'
        ];
        if (!$questionHelper->confirm(implode("\n", $confirmQuestionLines), false)) {
            return 1;
        }

        $title = $project->title;
        if ($input->isInteractive() && strlen($title)) {
            $confirmName = $questionHelper->askInput('Type the project title to confirm');
            if ($confirmName !== $title) {
                $this->stdErr->writeln('Incorrect project title (expected: ' . $title . ')');
                return 1;
            }
        }

        $subscriptionId = $project->getSubscriptionId();
        $subscription = $this->api()->getClient()->getSubscription($subscriptionId);
        if (!$subscription) {
            throw new \RuntimeException('Subscription not found: ' . $subscriptionId);
        }

        $subscription->delete();
        $this->api()->clearProjectsCache();

        $this->stdErr->writeln('');
        $this->stdErr->writeln('The project ' . $this->api()->getProjectLabel($project) . ' was deleted.');
        return 0;
    }
}
