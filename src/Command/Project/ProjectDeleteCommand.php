<?php
namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Exception\InvalidArgumentException as ConsoleInvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectDeleteCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('project:delete')
            ->setDescription('Delete a project')
            ->addArgument('project', InputArgument::OPTIONAL, 'The project ID');
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

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

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

    /**
     * {@inheritdoc}
     */
    protected function validateInput(InputInterface $input, $envNotRequired = false)
    {
        if ($projectId = $input->getArgument('project')) {
            if ($input->getOption('project')) {
                throw new ConsoleInvalidArgumentException(
                    'You cannot use both the <project> argument and the --project option'
                );
            }
            $input->setOption('project', $projectId);
        }
        parent::validateInput($input, $envNotRequired);
    }
}
