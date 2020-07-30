<?php
namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\PropertyFormatter;
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
            ->setAliases(['activities', 'act'])
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter activities by type')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of results displayed', 10)
            ->addOption('start', null, InputOption::VALUE_REQUIRED, 'Only activities created before this date will be listed')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Check activities on all environments')
            ->setDescription('Get a list of activities for an environment or project');
        Table::configureInput($this->getDefinition());
        PropertyFormatter::configureInput($this->getDefinition());
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
            $apiResource = $this->getSelectedEnvironment();
        } else {
            $environmentSpecific = false;
            $apiResource = $project;
        }

        $type = $input->getOption('type');

        /** @var \Platformsh\Cli\Service\ActivityLoader $loader */
        $loader = $this->getService('activity_loader');
        $activities = $loader->load($apiResource, $limit, $type, $startsAt);
        if (!$activities) {
            $this->stdErr->writeln('No activities found');

            return 1;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $headers = ['ID', 'Created', 'Completed', 'Description', 'Progress', 'State', 'Result', 'Environment(s)'];
        $defaultColumns = ['ID', 'Created', 'Description', 'Progress', 'State', 'Result'];

        if (!$environmentSpecific) {
            $defaultColumns[] = 'Environment(s)';
        }

        $rows = [];
        foreach ($activities as $activity) {
            $rows[] = [
                new AdaptiveTableCell($activity->id, ['wrap' => false]),
                $formatter->format($activity['created_at'], 'created_at'),
                $formatter->format($activity['completed_at'], 'completed_at'),
                ActivityMonitor::getFormattedDescription($activity, !$table->formatIsMachineReadable()),
                $activity->getCompletionPercent() . '%',
                ActivityMonitor::formatState($activity->state),
                ActivityMonitor::formatResult($activity->result, !$table->formatIsMachineReadable()),
                implode(', ', $activity->environments)
            ];
        }

        if (!$table->formatIsMachineReadable()) {
            if ($environmentSpecific) {
                $this->stdErr->writeln(sprintf(
                    'Activities on the project %s, environment %s:',
                    $this->api()->getProjectLabel($project),
                    $this->api()->getEnvironmentLabel($apiResource)
                ));
            } else {
                $this->stdErr->writeln(sprintf(
                    'Activities on the project %s:',
                    $this->api()->getProjectLabel($project)
                ));
            }
        }

        $table->render($rows, $headers, $defaultColumns);

        if (!$table->formatIsMachineReadable()) {
            $executable = $this->config()->get('application.executable');

            $max = $input->getOption('limit') ? (int) $input->getOption('limit') : 10;
            $maybeMoreAvailable = count($activities) === $max;
            if ($maybeMoreAvailable) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(sprintf(
                    'More activities may be available.'
                    . ' To display older activities, increase <info>--limit</info> above %d, or set <info>--start</info> to a date in the past.'
                    . ' For more information, run: <info>%s activity:list -h</info>',
                    $max,
                    $executable
                ));
            }

            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To view the log for an activity, run: <info>%s activity:log [id]</info>',
                $executable
            ));
            $this->stdErr->writeln(sprintf(
                'To view more information about an activity, run: <info>%s activity:get [id]</info>',
                $executable
            ));
        }

        return 0;
    }
}
