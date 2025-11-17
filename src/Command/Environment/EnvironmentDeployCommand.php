<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
            ->setAliases(['deploy','e:deploy','env:deploy'])
            ->setDescription('Deploy an environment\'s staged changes')
            ->addOption('strategy', 's', InputOption::VALUE_REQUIRED,
                'The deployment strategy, stopstart (default, restart with a shutdown) or rolling (zero downtime)');
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
                'result' => ActivityMonitor::formatResult($activity, !$table->formatIsMachineReadable()),
            ];
            $rows[] = $row;
        }

        $this->stdErr->writeln(sprintf('The following changes will be deployed to the environment %s:',
            $this->api()->getEnvironmentLabel($environment, 'comment')
        ));
        $table->render($rows, $this->tableHeader);

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        $strategy = $input->getOption('strategy');
        $can_rolling_deploy = $environment->getProperty('can_rolling_deploy', false);
        if (is_null($strategy)) {
            if ($can_rolling_deploy) {
                $options = [
                    'stopstart' => 'Restart with a shutdown',
                    'rolling' => 'Zero downtime deployment'
                ];
                $strategy = $questionHelper->chooseAssoc($options, 'Choose the deployment strategy: ', 'stopstart');
            } else {
                $strategy = 'stopstart';
            }
        } else {
            if (!in_array($strategy, ['stopstart', 'rolling'])) {
                $this->stdErr->writeln('The chosen strategy is not available for this environment.');
                return 1;
            } elseif (!$can_rolling_deploy && $strategy === 'rolling') {
                $this->stdErr->writeln('The chosen strategy is not available for this environment.');
                return 1;
            }
        }
        if ($strategy === 'rolling') {
            $this->stdErr->writeln('Please make sure the changes from above are not affecting the state of the services.');
        }
        if (!$questionHelper->confirm('Are you sure you want to continue?')) {
            return 1;
        }

        $result = $environment->runOperation('deploy', 'POST', ['strategy' => $strategy]);

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
