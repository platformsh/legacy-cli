<?php
namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Command\PlatformCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectDeleteCommand extends PlatformCommand
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
        $client = $this->getClient();

        $account = $client->getAccountInfo();
        if ($account['uuid'] != $project->owner) {
            $this->stdErr->writeln("Only the project's owner can delete it.");
            return 1;
        }

        /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');
        $title = $project->title;

        $confirmQuestion = "You are about to delete the project:"
          . "\n  <comment>$title</comment> (<comment>{$project->id}</comment>)"
          . "\n\n * This action is <options=bold>irreversible</>."
          . "\n * Your site will no longer be accessible."
          . "\n * All data associated with this project will be deleted, including backups."
          . "\n * You will be charged at the end of the month for any remaining project costs."
          . "\n\nAre you sure you want to delete this project?";
        if (!$questionHelper->confirm($confirmQuestion, $input, $output, false)) {
            return 1;
        }

        if ($input->isInteractive() && strlen($title)) {
            $confirmName = $questionHelper->askInput("Type the project title to confirm", $input, $this->stdErr);
            if ($confirmName !== $title) {
                $this->stdErr->writeln("Incorrect project title (expected: $title)");
                return 1;
            }
        }

        $subscriptionId = $project->getSubscriptionId();
        $subscription = $client->getSubscription($subscriptionId);

        $subscription->delete();
        $this->clearProjectsCache();

        $this->stdErr->writeln("\nThe project <info>$title</info> (<info>{$project->id}</info>) was deleted.");
        return 0;
    }
}
