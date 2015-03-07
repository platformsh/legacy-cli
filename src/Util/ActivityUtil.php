<?php

namespace Platformsh\Cli\Util;

use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

abstract class ActivityUtil
{

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
        $output->writeln("Waiting...");
        $activity->wait(
          null,
          function ($log) use ($output) {
              $output->write(preg_replace('/^/m', '    ', $log));
          }
        );
        switch ($activity->getState()) {
            case Activity::SUCCESS:
                if ($success !== null) {
                    $output->writeln($success);
                }
                return true;

            case Activity::FAILURE:
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
     */
    public static function waitMultiple(array $activities, OutputInterface $output)
    {
        $count = count($activities);
        if ($count <= 0) {
            return;
        }
        $complete = 0;
        $output->writeln("Waiting...");
        $bar = new ProgressBar($output);
        $bar->start($count);
        $bar->setFormat('verbose');
        while ($complete < $count) {
            sleep(1);
            foreach ($activities as $activity) {
                if (!$activity->isComplete()) {
                    $activity->refresh();
                } else {
                    $complete++;
                }
            }
            $bar->setProgress($complete);
        }
        $bar->finish();
        $output->writeln('');
    }

}
