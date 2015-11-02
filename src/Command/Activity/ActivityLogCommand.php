<?php
namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Command\PlatformCommand;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityLogCommand extends PlatformCommand
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
          ->setDescription('Display the log for an environment activity');
        $this->addProjectOption()
             ->addEnvironmentOption();
        $this->addExample('Display the log for the last push', '--type environment.push');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $id = $input->getArgument('id');
        if ($id) {
            $activity = $this->getSelectedEnvironment()
                             ->getActivity($id);
            if (!$activity) {
                $this->stdErr->writeln("Activity not found: <error>$id</error>");

                return 1;
            }
        } else {
            $activities = $this->getSelectedEnvironment()
                               ->getActivities(1, $input->getOption('type'));
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
        if ($refresh > 0 && $this->isTerminal($output) && !$activity->isComplete()) {
            $activity->wait(
                null,
                function ($log) use ($output) {
                    $output->write($log);
                },
                $refresh
            );
        }
        else {
            $output->write($activity->log);
        }

        return 0;
    }

}
