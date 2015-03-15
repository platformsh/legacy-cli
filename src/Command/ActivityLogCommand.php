<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Activity;
use CommerceGuys\Platform\Cli\Model\Environment;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

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
            ->addOption('refresh', null, InputOption::VALUE_OPTIONAL, 'Log refresh interval (seconds). Set to 0 to disable refreshing.', 1)
            ->addOption('type', null, InputOption::VALUE_OPTIONAL, 'Filter activities by type')
            ->setDescription('Display the log for an environment activity');
        $this->addProjectOption()->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $client = $this->getPlatformClient($this->environment['endpoint']);

        $id = $input->getArgument('id');
        if ($id) {
            $activity = Activity::get($id, $this->environment['endpoint'] . '/activities', $client);
            if (!$activity) {
                $output->writeln("Activity not found: <error>$id</error>");
                return 1;
            }
        }
        else {
            $environment = new Environment($this->environment, $client);
            $activities = $environment->getActivities(1, $input->getOption('type'));
            /** @var Activity $activity */
            $activity = reset($activities);
            if (!$activity) {
                $output->writeln('No activities found');
                return 1;
            }
        }

        $output->writeln("Log for activity <info>" . $activity->id() . "</info> (" . $activity->getDescription() . "):");

        $refresh = $input->getOption('refresh');
        $poll = $refresh > 0 && $this->isTerminal($output);
        $this->displayLog($activity, $output, $poll, $refresh);
        return 0;
    }

    /**
     * @param Activity        $activity
     * @param OutputInterface $output
     * @param bool            $poll
     * @param float|int       $interval A refresh interval (in seconds).
     */
    protected function displayLog(Activity $activity, OutputInterface $output, $poll = true, $interval = 1)
    {
        $activity->wait(
          null,
          function ($log) use ($output) {
              $output->write(preg_replace('/^/m', '    ', $log));
          },
          $interval
        );
    }

}
