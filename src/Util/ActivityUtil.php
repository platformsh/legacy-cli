<?php

namespace Platformsh\Cli\Util;

use Platformsh\Client\Model\Activity;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

abstract class ActivityUtil
{

    protected static $stateNames = [
        Activity::STATE_PENDING => 'pending',
        Activity::STATE_COMPLETE => 'complete',
        Activity::STATE_IN_PROGRESS => 'in progress',
    ];

    /**
     * Wait for a single activity to complete, and display the log continuously.
     *
     * @param Activity        $activity
     * @param OutputInterface $output
     * @param string          $success
     * @param string          $failure
     *
     * @return bool
     *   True if the activity succeeded, false otherwise.
     */
    public static function waitAndLog(Activity $activity, OutputInterface $output, $success = null, $failure = null)
    {
        $output->writeln('Waiting for the activity <info>' . $activity->id . '</info> (' . $activity->getDescription() . "):");

        // Initialize a progress bar which will show elapsed time and the
        // activity's state.
        $bar = new ProgressBar($output);
        $bar->setPlaceholderFormatterDefinition('state', function () use ($activity) {
            return self::formatState($activity->state);
        });
        $bar->setFormat("  [%bar%] %elapsed:6s% (%state%)");
        $bar->start();

        // Wait for the activity to complete.
        $activity->wait(
            // Advance the progress bar whenever the activity is polled.
            function () use ($bar) {
                $bar->advance();
            },
            // Display new log output when it is available.
            function ($log) use ($output, $bar) {
                // Clear the progress bar and ensure the current line is flushed.
                $bar->clear();
                $output->write($output->isDecorated() ? "\n\033[1A" : "\n");

                // Display the new log output, with an indent.
                $output->write(preg_replace('/^/m', '    ', $log));

                // Display the progress bar again.
                $bar->advance();
            }
        );
        $bar->finish();
        $output->writeln('');

        // Display the success or failure messages.
        switch ($activity['result']) {
            case Activity::RESULT_SUCCESS:
                $output->writeln($success ?: "Activity <info>{$activity->id}</info> succeeded");
                return true;

            case Activity::RESULT_FAILURE:
                $output->writeln($failure ?: "Activity <error>{$activity->id}</error> failed");
                return false;
        }

        return false;
    }

    /**
     * Wait for multiple activities to complete.
     *
     * A progress bar tracks the state of each activity. The activity log is
     * only displayed at the end, if an activity failed.
     *
     * @param Activity[]      $activities
     * @param OutputInterface $output
     * @param Project         $project
     *
     * @return bool
     *   True if all activities succeed, false otherwise.
     */
    public static function waitMultiple(array $activities, OutputInterface $output, Project $project)
    {
        $count = count($activities);
        if ($count == 0) {
            return true;
        } elseif ($count === 1) {
            return self::waitAndLog(reset($activities), $output);
        }

        $output->writeln("Waiting for $count activities...");

        // Initialize a progress bar which will show elapsed time and all of the
        // activities' states.
        $bar = new ProgressBar($output);
        $states = [];
        foreach ($activities as $activity) {
            $state = $activity->state;
            $states[$state] = isset($states[$state]) ? $states[$state] + 1 : 1;
        }
        $bar->setPlaceholderFormatterDefinition('states', function () use (&$states) {
            $format = '';
            foreach ($states as $state => $count) {
                $format .= $count . ' ' . self::formatState($state) . ', ';
            }

            return rtrim($format, ', ');
        });
        $bar->setFormat("  [%bar%] %elapsed:6s% (%states%)");
        $bar->start();

        // Get the most recent created date of each of the activities, as a Unix
        // timestamp, so that they can be more efficiently refreshed.
        $mostRecentTimestamp = 0;
        foreach ($activities as $activity) {
            $created = strtotime($activity->created_at);
            $mostRecentTimestamp = $created > $mostRecentTimestamp ? $created : $mostRecentTimestamp;
        }

        // Wait for the activities to complete, polling (refreshing) all of them
        // with a 1 second delay.
        $complete = 0;
        while ($complete < $count) {
            sleep(1);
            $states = [];
            $complete = 0;
            // Get a list of activities on the project. Any of our activities
            // which are not contained in this list must be refreshed
            // individually.
            $projectActivities = $project->getActivities(0, null, $mostRecentTimestamp ?: null);
            foreach ($activities as $activity) {
                $refreshed = false;
                foreach ($projectActivities as $projectActivity) {
                    if ($projectActivity->id === $activity->id) {
                        $activity = $projectActivity;
                        $refreshed = true;
                        break;
                    }
                }
                if (!$refreshed && !$activity->isComplete()) {
                    $activity->refresh();
                }
                if ($activity->isComplete()) {
                    $complete++;
                }
                $state = $activity->state;
                $states[$state] = isset($states[$state]) ? $states[$state] + 1 : 1;
            }
            $bar->advance();
        }
        $bar->finish();
        $output->writeln('');

        // Display success or failure messages for each activity.
        $success = true;
        foreach ($activities as $activity) {
            $description = $activity->getDescription();
            switch ($activity['result']) {
                case Activity::RESULT_SUCCESS:
                    $output->writeln("Activity <info>{$activity->id}</info> succeeded: $description");
                    break;

                case Activity::RESULT_FAILURE:
                    $success = false;
                    $output->writeln("Activity <error>{$activity->id}</error> failed");

                    // If the activity failed, show the complete log.
                    $output->writeln("  Description: $description");
                    $output->writeln("  Log:");
                    $output->writeln(preg_replace('/^/m', '    ', $activity->log));
                    break;
            }
        }

        return $success;
    }

    /**
     * Format a state name.
     *
     * @param string $state
     *
     * @return string
     */
    public static function formatState($state)
    {
        return isset(self::$stateNames[$state]) ? self::$stateNames[$state] : $state;
    }
}
