<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Integration;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\QuestionHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'integration:delete', description: 'Delete an integration from a project')]
class IntegrationDeleteCommand extends IntegrationCommandBase
{
    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::OPTIONAL, 'The integration ID. Leave blank to choose from a list.');
        $this->selector->addProjectOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->activityMonitor->addWaitOptions($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);
        $project = $selection->getProject();

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

        if ($this->activityMonitor->shouldWait($input)) {
            $activityMonitor = $this->activityMonitor;
            $activityMonitor->waitMultiple($result->getActivities(), $selection->getProject());
        }

        $this->updateGitUrl($oldGitUrl, $project);

        return 0;
    }
}
