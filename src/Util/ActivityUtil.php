<?php

namespace Platformsh\Cli\Util;

use Platformsh\Client\Model\Activity;
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
     * Wait for a single activity to complete, and display the log.
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
        $bar = new ProgressBar($output);
        $bar->setPlaceholderFormatterDefinition('state', function () use ($activity) {
            return self::formatState($activity->state);
        });
        $bar->setFormat("  [%bar%] %elapsed:6s% (%state%)");
        $bar->start();
        $activity->wait(
          function () use ($bar) {
              $bar->advance();
          },
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
        switch ($activity['result']) {
            case 'success':
                if ($success !== null) {
                    $output->writeln($success);
                }
                return true;

            case 'failure':
                if ($failure !== null) {
                    $output->writeln($failure);
                }
        }
        return false;
    }

    /**
     * Wait for multiple activities to complete.
     *
     * @param Activity[]      $activities
     * @param OutputInterface $output
     * @param string|null $message
     *
     * @return bool
     *   True if all activities succeed, false otherwise.
     */
    public static function waitMultiple(array $activities, OutputInterface $output, $message = null)
    {
        $count = count($activities);
        if ($count == 0) {
            return true;
        }
        $complete = 0;
        if ($message === null) {
            $activitiesPlural = $count > 1 ? 'activities' : 'activity';
            $message = "Waiting for the $activitiesPlural to complete...";
        }
        $output->writeln($message);
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
        while ($complete < $count) {
            sleep(1);
            $states = [];
            foreach ($activities as $activity) {
                if (!$activity->isComplete()) {
                    $activity->refresh();
                } else {
                    $complete++;
                }
                $state = $activity->state;
                $states[$state] = isset($states[$state]) ? $states[$state] + 1 : 1;
            }
            $bar->advance();
        }
        $bar->finish();
        $output->writeln('');

        $success = true;
        foreach ($activities as $activity) {
            // Display a message for successful activities.
            if ($activity->result === Activity::RESULT_SUCCESS) {
                $output->writeln("Activity <info>{$activity->id}</info> (" . $activity->getDescription() . ") succeeded");
            }
            // Display the activity log if there was a failure.
            elseif ($activity->result === Activity::RESULT_FAILURE) {
                $success = false;
                $output->writeln("Activity <error>{$activity->id}</error> (" . $activity->getDescription() . ") failed");
                $output->writeln("Log:");
                $output->writeln(preg_replace('/^/m', '    ', $activity->log));
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
