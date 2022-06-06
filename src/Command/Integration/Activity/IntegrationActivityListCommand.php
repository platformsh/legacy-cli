<?php

namespace Platformsh\Cli\Command\Integration\Activity;

use Platformsh\Cli\Command\Integration\IntegrationCommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Console\ArrayArgument;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationActivityListCommand extends IntegrationCommandBase
{
    protected static $defaultName = 'integration:activity:list|i:act|integration:activities';
    protected static $defaultDescription = 'List activities for an integration';

    protected function configure()
    {
        $this->addArgument('id', InputArgument::OPTIONAL, 'An integration ID. Leave blank to choose from a list.')
            ->addOption('type', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter activities by type.' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('exclude-type', 'x', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exclude activities by type.' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of results displayed', 10)
            ->addOption('start', null, InputOption::VALUE_REQUIRED, 'Only activities created before this date will be listed')
            ->addOption('state', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter activities by state.' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('result', null, InputOption::VALUE_REQUIRED, 'Filter activities by result')
            ->addOption('incomplete', 'i', InputOption::VALUE_NONE, 'Only list incomplete activities');
        $this->table->configureInput($this->getDefinition());
        $this->formatter->configureInput($this->getDefinition());
        $this->selector->addProjectOption($this->getDefinition());
        $this->addOption('environment', 'e', InputOption::VALUE_REQUIRED, '[Deprecated option, not used]');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // TODO
        //$this->warnAboutDeprecatedOptions(['environment']);
        $selection = $this->selector->getSelection($input);
        $project = $selection->getProject();

        $integration = $this->selectIntegration($project, $input->getArgument('id'), $input->isInteractive());
        if (!$integration) {
            return 1;
        }

        $activities = $this->loader->loadFromInput($integration, $input);
        if ($activities === []) {
            $this->stdErr->writeln('No activities found');

            return 1;
        }

        $headers = ['ID', 'Created', 'Completed', 'Description', 'Type', 'State', 'Result'];
        $defaultColumns = ['ID', 'Created', 'Description', 'Type', 'State', 'Result'];

        $rows = [];
        foreach ($activities as $activity) {
            $rows[] = [
                new AdaptiveTableCell($activity->id, ['wrap' => false]),
                $this->formatter->format($activity['created_at'], 'created_at'),
                $this->formatter->format($activity['completed_at'], 'completed_at'),
                $this->activityService->getFormattedDescription($activity, !$this->table->formatIsMachineReadable()),
                new AdaptiveTableCell($activity->type, ['wrap' => false]),
                $this->activityService->formatState($activity->state),
                $this->activityService->formatResult($activity->result, !$this->table->formatIsMachineReadable()),
            ];
        }

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Activities on the project %s, integration <info>%s</info> (%s):',
                $this->api->getProjectLabel($project),
                $integration->id,
                $integration->type
            ));
        }

        $this->table->render($rows, $headers, $defaultColumns);

        if (!$this->table->formatIsMachineReadable()) {
            $executable = $this->config->get('application.executable');

            $max = $input->getOption('limit') ? (int) $input->getOption('limit') : 10;
            $maybeMoreAvailable = count($activities) === $max;
            if ($maybeMoreAvailable) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(sprintf(
                    'More activities may be available.'
                    . ' To display older activities, increase <info>--limit</info> above %d, or set <info>--start</info> to a date in the past.'
                    . ' For more information, run: <info>%s integration:activity:list -h</info>',
                    $max,
                    $executable
                ));
            }

            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To view the log for an activity, run: <info>%s integration:activity:log [integration] [activity]</info>',
                $executable
            ));
            $this->stdErr->writeln(sprintf(
                'To view more information about an activity, run: <info>%s integration:activity:get [integration] [activity]</info>',
                $executable
            ));
        }

        return 0;
    }
}
