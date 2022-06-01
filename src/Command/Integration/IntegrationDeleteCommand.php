<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Integration;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationDeleteCommand extends IntegrationCommandBase
{
    protected static $defaultName = 'integration:delete';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'The integration ID. Leave blank to choose from a list.')
            ->setDescription('Delete an integration from a project');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->activityService->configureInput($definition);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->selector->getSelection($input)->getProject();

        $integration = $this->selectIntegration($project, $input->getArgument('id'), $input->isInteractive());
        if (!$integration) {
            return 1;
        }

        $confirmText = sprintf('Delete the integration <info>%s</info> (type: %s)?', $integration->id, $integration->type);
        if (!$this->questionHelper->confirm($confirmText)) {
            return 1;
        }

        $oldGitUrl = $project->getGitUrl();

        $result = $integration->delete();

        $this->stdErr->writeln(sprintf('Deleted integration <info>%s</info>', $integration->id));

        if ($this->activityService->shouldWait($input)) {
            $this->activityService->waitMultiple($result->getActivities(), $project);
        }

        $this->updateGitUrl($oldGitUrl, $project);

        return 0;
    }
}
