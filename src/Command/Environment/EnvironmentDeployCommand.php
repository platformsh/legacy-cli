<?php

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Selector\SelectorConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'environment:deploy', description: 'Deploy an environment\'s staged changes', aliases: ['deploy','e:deploy','env:deploy'])]
class EnvironmentDeployCommand extends CommandBase
{
    /** @var array<string, string> */
    private array $tableHeader = [
        'id' => 'ID',
        'created' => 'Created',
        'description' => 'Description',
        'type' => 'Type',
        'result' => 'Result',
    ];

    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Api $api, private readonly PropertyFormatter $propertyFormatter, private readonly QuestionHelper $questionHelper, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'strategy',
                's',
                InputOption::VALUE_REQUIRED,
                'The deployment strategy, stopstart (default, restart with a shutdown) or rolling (zero downtime)'
            );
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->activityMonitor->addWaitOptions($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection(
            $input,
            new SelectorConfig(
                chooseEnvFilter: SelectorConfig::filterEnvsByStatus(['active', 'paused'])
            ),
        );

        $environment = $selection->getEnvironment();

        if (!$environment->operationAvailable('deploy', true)) {
            $this->stdErr->writeln(
                "Operation not available: The environment " . $this->api->getEnvironmentLabel($environment, 'error') . " can't be deployed."
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
                $this->api->getEnvironmentLabel($environment, 'comment')
            ));
            return 0;
        }

        $rows = [];
        foreach ($activities as $activity) {
            $row = [
                'id' => new AdaptiveTableCell($activity->id, ['wrap' => false]),
                'created' => $this->propertyFormatter->format($activity['created_at'], 'created_at'),
                'description' => ActivityMonitor::getFormattedDescription($activity, !$this->table->formatIsMachineReadable()),
                'type' => new AdaptiveTableCell($activity->type, ['wrap' => false]),
                'result' => ActivityMonitor::formatResult($activity, !$this->table->formatIsMachineReadable()),
            ];
            $rows[] = $row;
        }

        $this->stdErr->writeln(sprintf(
            'The following changes will be deployed to the environment %s:',
            $this->api->getEnvironmentLabel($environment, 'comment')
        ));
        $this->table->render($rows, $this->tableHeader);

        $strategy = $input->getOption('strategy');
        $can_rolling_deploy = $environment->getProperty('can_rolling_deploy', false);
        if (is_null($strategy)) {
            if ($can_rolling_deploy) {
                $options = [
                    'stopstart' => 'Restart with a shutdown',
                    'rolling' => 'Zero downtime deployment',
                ];
                $strategy = $this->questionHelper->chooseAssoc($options, 'Choose the deployment strategy: ', 'stopstart');
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
        if (!$this->questionHelper->confirm('Are you sure you want to continue?')) {
            return 1;
        }

        $result = $environment->runOperation('deploy', 'POST', ['strategy' => $strategy]);

        if ($this->activityMonitor->shouldWait($input)) {
            $success = $this->activityMonitor->waitMultiple($result->getActivities(), $selection->getProject());
            if (!$success) {
                return 1;
            }
        }

        return 0;
    }
}
