<?php
namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityListCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('activity:list')
            ->setAliases(['activities'])
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter activities by type')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of results displayed', 10)
            ->addOption('start', null, InputOption::VALUE_REQUIRED, 'Only activities created before this date will be listed')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Check activities on all environments')
            ->setDescription('Get a list of activities for an environment or project');
        Table::configureInput($this->getDefinition());
        $this->addProjectOption()
             ->addEnvironmentOption();
        $this->addExample('List recent activities for the current environment')
             ->addExample('List all recent activities for the current project', '--all')
             ->addExample('List recent pushes', '--type environment.push')
             ->addExample('List pushes made before 15 March', '--type environment.push --start 2015-03-15');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, $input->getOption('all'));

        $project = $this->getSelectedProject();

        $startsAt = null;
        if ($input->getOption('start') && !($startsAt = strtotime($input->getOption('start')))) {
            $this->stdErr->writeln('Invalid date: <error>' . $input->getOption('start') . '</error>');
            return 1;
        }

        $limit = (int) $input->getOption('limit');

        if ($this->hasSelectedEnvironment() && !$input->getOption('all')) {
            $environmentSpecific = true;
            $environment = $this->getSelectedEnvironment();
            $activities = $environment->getActivities($limit, $input->getOption('type'), $startsAt);
        } else {
            $environmentSpecific = false;
            $activities = $project->getActivities($limit, $input->getOption('type'), $startsAt);
        }
        /** @var \Platformsh\Client\Model\Activity[] $activities */
        if (!$activities) {
            $this->stdErr->writeln('No activities found');

            return 1;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');

        $rows = [];
        foreach ($activities as $activity) {
            $row = [
                new AdaptiveTableCell($activity->id, ['wrap' => false]),
                date('Y-m-d H:i:s', strtotime($activity['created_at'])),
                $activity->getDescription(),
                $activity->getCompletionPercent() . '%',
                ActivityMonitor::formatState($activity->state),
                ActivityMonitor::formatResult($activity->result, !$table->formatIsMachineReadable()),
            ];
            if (!$environmentSpecific) {
                $row[] = implode(', ', $activity->environments);
            }
            $rows[] = $row;
        }

        $headers = ['ID', 'Created', 'Description', 'Progress', 'State', 'Result'];

        if (!$environmentSpecific) {
            $headers[] = 'Environment(s)';
        }

        if (!$table->formatIsMachineReadable()) {
            if ($environmentSpecific && isset($environment)) {
                $this->stdErr->writeln(
                    sprintf(
                        'Activities for the environment <info>%s</info>:',
                        $environment->id
                    )
                );
            } elseif (!$environmentSpecific) {
                $this->stdErr->writeln(
                    sprintf(
                        'Activities for the project <info>%s</info>:',
                        $project->id
                    )
                );
            }
        }

        $table->render($rows, $headers);

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(
                sprintf(
                    'To view the log for an activity, run: <info>%s activity:log [id]</info>',
                    $this->config()->get('application.executable')
                )
            );
        }

        return 0;
    }
}
