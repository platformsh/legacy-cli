<?php
namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityLogCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('activity:log')
            ->addArgument('id', InputArgument::OPTIONAL, 'The activity ID. Defaults to the most recent activity.')
            ->addOption(
                'refresh',
                null,
                InputOption::VALUE_REQUIRED,
                'Log refresh interval (seconds). Set to 0 to disable refreshing.',
                1
            )
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter activities by type')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Check activities on all environments')
            ->setDescription('Display the log for an activity');
        $this->addProjectOption()
             ->addEnvironmentOption();
        $this->addExample('Display the log for the last push on the current environment', '--type environment.push')
            ->addExample('Display the log for the last activity on the current project', '--all');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, !$input->getOption('all'));

        $id = $input->getArgument('id');
        if ($id) {
            $activity = $this->getSelectedProject()
                             ->getActivity($id);
            if (!$activity) {
                $this->stdErr->writeln("Activity not found: <error>$id</error>");

                return 1;
            }
        } else {
            if ($this->hasSelectedEnvironment() && !$input->getOption('all')) {
                $activities = $this->getSelectedEnvironment()
                    ->getActivities(1, $input->getOption('type'));
            } else {
                $activities = $this->getSelectedProject()
                    ->getActivities(1, $input->getOption('type'));
            }
            /** @var Activity $activity */
            $activity = reset($activities);
            if (!$activity) {
                $this->stdErr->writeln('No activities found');

                return 1;
            }
        }

        $this->stdErr->writeln(
            "Log for activity <info>" . $activity->id . "</info> (" . $activity->getDescription() . "):\n"
        );

        $refresh = $input->getOption('refresh');
        if ($refresh > 0 && !$this->runningViaMulti && $output->isDecorated() && !$activity->isComplete()) {
            $activity->wait(
                null,
                function ($log) use ($output) {
                    $output->write($log);
                },
                $refresh
            );

            // Once the activity is complete, something has probably changed in
            // the project's environments, so this is a good opportunity to
            // clear the cache.
            $this->api()->clearEnvironmentsCache($activity->project);
        } else {
            $output->write($activity->log);
        }

        return 0;
    }
}
