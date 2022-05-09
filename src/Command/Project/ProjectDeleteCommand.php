<?php
namespace Platformsh\Cli\Command\Project;

use GuzzleHttp\Exception\ClientException;
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
        if ($projectId = $input->getArgument('project')) {
            if ($input->getOption('project')) {
                throw new ConsoleInvalidArgumentException(
                    'You cannot use both the <project> argument and the --project option'
                );
            }
            $input->setOption('project', $projectId);
        }
        $this->validateInput($input);

        $project = $this->getSelectedProject();
        $subscriptionId = $project->getSubscriptionId();
        $subscription = $this->api()->loadSubscription($subscriptionId, $project);
        if (!$subscription) {
            $this->stdErr->writeln('Subscription not found: <error>' . $subscriptionId . '</error>');
            $this->stdErr->writeln('Unable to delete the project.');
            return 1;
        }
        // TODO check for a HAL 'delete' link on the subscription?

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

        try {
            $subscription->delete();
        } catch (ClientException $e) {
            $response = $e->getResponse();
            if ($response !== null && $response->getStatusCode() === 403 && !$this->config()->getWithDefault('api.organizations', false)) {
                if ($project->owner !== $this->api()->getMyUserId()) {
                    $this->stdErr->writeln("Only the project's owner can delete it.");
                    return 1;
                }
            }
            throw $e;
        }

        $this->api()->clearProjectsCache();

        $this->stdErr->writeln('');
        $this->stdErr->writeln('The project ' . $this->api()->getProjectLabel($project) . ' was deleted.');
        return 0;
    }
}
