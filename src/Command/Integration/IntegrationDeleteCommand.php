<?php
namespace Platformsh\Cli\Command\Integration;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationDeleteCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('integration:delete')
            ->addArgument('id', InputArgument::REQUIRED, 'The integration ID')
            ->setDescription('Delete an integration from a project');
        $this->addProjectOption()->addNoWaitOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $id = $input->getArgument('id');
        $project = $this->getSelectedProject();

        $integration = $project->getIntegration($id);
        if (!$integration) {
            try {
                $integration = $this->api()->matchPartialId($id, $project->getIntegrations(), 'Integration');
            } catch (\InvalidArgumentException $e) {
                $this->stdErr->writeln($e->getMessage());
                return 1;
            }
        }

        $confirmText = sprintf('Delete the integration <info>%s</info> (type: %s)?', $integration->id, $integration->type);
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if (!$questionHelper->confirm($confirmText)) {
            return 1;
        }

        $result = $integration->delete();

        $this->stdErr->writeln(sprintf('Deleted integration <info>%s</info>', $integration->id));

        if (!$input->getOption('no-wait')) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $activityMonitor->waitMultiple($result->getActivities(), $this->getSelectedProject());
        }

        return 0;
    }
}
