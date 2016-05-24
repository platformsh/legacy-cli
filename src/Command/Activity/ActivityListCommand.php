<?php
namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Util\ActivityUtil;
use Platformsh\Cli\Util\Table;
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
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of results displayed', 5)
            ->addOption('start', null, InputOption::VALUE_REQUIRED, 'Only activities created before this date will be listed')
            ->setDescription('Get a list of activities for an environment');
        Table::addFormatOption($this->getDefinition());
        $this->addProjectOption()
             ->addEnvironmentOption();
        $this->addExample('List recent activities on the current environment')
             ->addExample('List recent pushes', '--type environment.push')
             ->addExample('List pushes made before 15 March', '--type environment.push --start 2015-03-15');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $environment = $this->getSelectedEnvironment();

        $startsAt = null;
        if ($input->getOption('start') && !($startsAt = strtotime($input->getOption('start')))) {
            $this->stdErr->writeln('Invalid date: <error>' . $input->getOption('start') . '</error>');
            return 1;
        }

        $limit = (int) $input->getOption('limit');
        $activities = $environment->getActivities($limit, $input->getOption('type'), $startsAt);
        if (!$activities) {
            $this->stdErr->writeln('No activities found');

            return 1;
        }

        $table = new Table($input, $output);

        $rows = [];
        foreach ($activities as $activity) {
            $rows[] = [
                new AdaptiveTableCell($activity->id, ['wrap' => false]),
                date('Y-m-d H:i:s', strtotime($activity['created_at'])),
                $activity->getDescription(),
                $activity->getCompletionPercent(),
                ActivityUtil::formatState($activity->state),
            ];
        }

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln("Activities for the environment <info>" . $environment->id . "</info>");
        }

        $headers = ['ID', 'Created', 'Description', '% Complete', 'State'];

        $table->render($rows, $headers);

        return 0;
    }

}
