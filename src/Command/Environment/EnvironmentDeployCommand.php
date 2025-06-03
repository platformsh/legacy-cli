<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentDeployCommand extends CommandBase
{
    private $tableHeader = [
        'id' => 'ID',
        'created' => 'Created',
        'description' => 'Description',
        'type' => 'Type',
        'result' => 'Result',
    ];

    protected function configure()
    {
        $this
            ->setName('environment:deploy')
            ->setAliases(['deploy'])
            ->setDescription('Deploy an environment\'s staged changes');
        $this->addProjectOption()
            ->addEnvironmentOption();
        $this->addWaitOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->chooseEnvFilter = $this->filterEnvsByStatus(['active', 'paused']);
        $this->validateInput($input);

        $environment = $this->getSelectedEnvironment();

        if (!$environment->operationAvailable('deploy', true)) {
            $this->stdErr->writeln(
                "Operation not available: The environment " . $this->api()->getEnvironmentLabel($environment, 'error') . " can't be deployed."
            );

            if (!$environment->isActive()) {
                $this->stdErr->writeln('The environment is not active.');
            }

            return 1;
        }

        $activities = $environment->getActivities(0, null, null, Activity::STATE_STAGED);
        if (count($activities) < 1) {
            $this->stdErr->writeln(sprintf(
                'The environment %s has no staged changes to deploy.',
                $this->api()->getEnvironmentLabel($environment, 'comment')
            ));
            return 0;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $rows = [];
        foreach ($activities as $activity) {
            $row = [
                'id' => new AdaptiveTableCell($activity->id, ['wrap' => false]),
                'created' => $formatter->format($activity['created_at'], 'created_at'),
                'description' => ActivityMonitor::getFormattedDescription($activity, !$table->formatIsMachineReadable()),
                'type' => new AdaptiveTableCell($activity->type, ['wrap' => false]),
                'result' => ActivityMonitor::formatResult($activity->result, !$table->formatIsMachineReadable()),
            ];
            $rows[] = $row;
        }

        $this->stdErr->writeln(sprintf('The following changes will be deployed to the environment %s:',
            $this->api()->getEnvironmentLabel($environment, 'comment')
        ));
        $table->render($rows, $this->tableHeader);

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if (!$questionHelper->confirm('Are you sure you want to continue?')) {
            return 1;
        }

        $result = $environment->runOperation('deploy');

        if ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $success = $activityMonitor->waitMultiple($result->getActivities(), $this->getSelectedProject());
            if (!$success) {
                return 1;
            }
        }

        return 0;
    }
}
