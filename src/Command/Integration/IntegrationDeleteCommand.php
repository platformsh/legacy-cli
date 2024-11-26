<?php
namespace Platformsh\Cli\Command\Integration;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'integration:delete', description: 'Delete an integration from a project')]
class IntegrationDeleteCommand extends IntegrationCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->addArgument('id', InputArgument::OPTIONAL, 'The integration ID. Leave blank to choose from a list.');
        $this->addProjectOption()->addWaitOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateInput($input);
        $project = $this->getSelectedProject();

        $integration = $this->selectIntegration($project, $input->getArgument('id'), $input->isInteractive());
        if (!$integration) {
            return 1;
        }

        $confirmText = sprintf('Delete the integration <info>%s</info> (type: %s)?', $integration->id, $integration->type);
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if (!$questionHelper->confirm($confirmText)) {
            return 1;
        }

        $oldGitUrl = $project->getGitUrl();

        $result = $integration->delete();

        $this->stdErr->writeln(sprintf('Deleted integration <info>%s</info>', $integration->id));

        if ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $activityMonitor->waitMultiple($result->getActivities(), $this->getSelectedProject());
        }

        $this->updateGitUrl($oldGitUrl);

        return 0;
    }
}
