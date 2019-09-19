<?php

namespace Platformsh\Cli\Service;

use Platformsh\Client\Model\Activity;
use Platformsh\Client\Model\ActivityLog\LogItem;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityMonitor
{

    protected static $resultNames = [
        Activity::RESULT_FAILURE => 'failure',
        Activity::RESULT_SUCCESS => 'success',
    ];

    protected static $stateNames = [
        Activity::STATE_PENDING => 'pending',
        Activity::STATE_COMPLETE => 'complete',
        Activity::STATE_IN_PROGRESS => 'in progress',
    ];

    protected $output;

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
    }

    /**
     * @return \Symfony\Component\Console\Output\OutputInterface
     */
    protected function getStdErr()
    {
        return $this->output instanceof ConsoleOutputInterface ? $this->output->getErrorOutput() : $this->output;
    }

    /**
     * Indent a multi-line string.
     *
     * @param string $string
     * @param string $prefix
     *
     * @return string
     */
    protected function indent($string, $prefix = '    ')
    {
        return preg_replace('/^/m', $prefix, $string);
    }

    /**
     * Wait for a single activity to complete, and display the log continuously.
     *
     * @param Activity    $activity     The activity.
     * @param string|null $success      A message to show on success.
     * @param string|null $failure      A message to show on failure.
     * @param int|float   $pollInterval The polling interval in seconds.
     * @param bool        $timestamps   Whether to display timestamps.
     * @param bool        $progress     Whether to show a progress bar.
     * @param bool        $decorate     Whether to show context messages and a progress bar.
     *
     * @return bool True if the activity succeeded, false otherwise.
     */
    public function waitAndLog(Activity $activity, $success = null, $failure = null, $pollInterval = 1, $timestamps = false, $progress = true, $decorate = true)
    {
        $stdErr = $this->getStdErr();

        if ($decorate) {
            $stdErr->writeln(sprintf(
                'Waiting for the activity <info>%s</info> (%s):',
                $activity->id,
                self::getFormattedDescription($activity)
            ));
        }

        // The progress bar will show elapsed time and the activity's state.
        if ($activity->isComplete()) {
            $progress = false;
        }
        $bar = $this->newProgressBar($progress ? $stdErr : new NullOutput());
        $startTime = $this->getStart($activity) ?: time();
        $bar->setPlaceholderFormatterDefinition('elapsed', function () use ($startTime) {
            return Helper::formatTime(time() - $startTime);
        });
        $bar->setFormat('[%bar%] %elapsed:6s%');
        $bar->start();

        $formatItem = function (LogItem $item) use ($timestamps) {
            if ($timestamps) {
                return $item->getTime()->format('[Y-m-d H:i:s]') . "\t" . $item->getMessage();
            }

            return $item->getMessage();
        };

        $activity->readLog(function (array $items) use ($bar, $stdErr, $formatItem) {
            // Clear the progress bar and ensure the current line is flushed.
            $bar->clear();
            $stdErr->write($stdErr->isDecorated() ? "\n\033[1A" : "\n");

            // Display the new log output.
            $stdErr->write(implode('', array_map($formatItem, $items)));

            // Display the progress bar again.
            $bar->advance();
        });

        // Finally, wait for the activity to complete.
        if (!$activity->isComplete()) {
            $activity->wait(function () use ($bar) { $bar->advance(); }, null, $pollInterval);
        }

        $succeeded = $activity['result'] === Activity::RESULT_SUCCESS;
        if (!$decorate) {
            return $succeeded;
        }

        $bar->finish();
        $stdErr->writeln('');

        // Display the success or failure messages.
        if ($succeeded) {
            $stdErr->writeln($success ?: "Activity <info>{$activity->id}</info> succeeded");
        } else {
            $stdErr->writeln($failure ?: "Activity <error>{$activity->id}</error> failed");
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
     * @param Project         $project
     *
     * @return bool
     *   True if all activities succeed, false otherwise.
     */
    public function waitMultiple(array $activities, Project $project)
    {
        $stdErr = $this->getStdErr();

        $count = count($activities);
        if ($count == 0) {
            return true;
        } elseif ($count === 1) {
            return $this->waitAndLog(reset($activities));
        }

        $stdErr->writeln(sprintf('Waiting for %d activities...', $count));

        // The progress bar will show elapsed time and all of the activities'
        // states.
        $bar = $this->newProgressBar($stdErr);
        $states = [];
        foreach ($activities as $activity) {
            $state = $activity->state;
            $states[$state] = isset($states[$state]) ? $states[$state] + 1 : 1;
        }
        $bar->setPlaceholderFormatterDefinition('states', function () use (&$states) {
            $format = '';
            foreach ($states as $state => $count) {
                $format .= $count . ' ' . $this->formatState($state) . ', ';
            }

            return rtrim($format, ', ');
        });
        $bar->setFormat('  [%bar%] %elapsed:6s% (%states%)');
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
        $stdErr->writeln('');

        // Display success or failure messages for each activity.
        $success = true;
        foreach ($activities as $activity) {
            $description = self::getFormattedDescription($activity);
            switch ($activity['result']) {
                case Activity::RESULT_SUCCESS:
                    $stdErr->writeln(sprintf('Activity <info>%s</info> succeeded: %s', $activity->id, $description));
                    break;

                case Activity::RESULT_FAILURE:
                    $success = false;
                    $stdErr->writeln(sprintf('Activity <error>%s</error> failed', $activity->id));

                    // If the activity failed, show the complete log.
                    $stdErr->writeln('  Description: ' . $description);
                    $stdErr->writeln('  Log:');
                    $stdErr->writeln($this->indent(implode($activity->readLog())));
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

    /**
     * Format a result.
     *
     * @param string $result
     * @param bool   $decorate
     *
     * @return string
     */
    public static function formatResult($result, $decorate = true)
    {
        $name = isset(self::$stateNames[$result]) ? self::$stateNames[$result] : $result;

        return $decorate && $result === Activity::RESULT_FAILURE
            ? '<error>' . $name . '</error>'
            : $name;
    }

    /**
     * Initialize a new progress bar.
     *
     * @param OutputInterface $output
     *
     * @return ProgressBar
     */
    protected function newProgressBar(OutputInterface $output)
    {
        // If the console output is not decorated (i.e. it does not support
        // ANSI), use NullOutput to suppress the progress bar entirely.
        $progressOutput = $output->isDecorated() ? $output : new NullOutput();

        return new ProgressBar($progressOutput);
    }

    /**
     * Get the formatted description of an activity.
     *
     * @param \Platformsh\Client\Model\Activity $activity
     * @param bool                              $withDecoration
     *
     * @return string
     */
    public static function getFormattedDescription(Activity $activity, $withDecoration = true)
    {
        if (!$withDecoration) {
            return $activity->getDescription(false);
        }
        $value = $activity->getDescription(true);

        // Replace description HTML elements with Symfony Console decoration
        // tags.
        $value = preg_replace('@<[^/][^>]+>@', '<options=underscore>', $value);
        $value = preg_replace('@</[^>]+>@', '</>', $value);

        // Replace literal tags like "&lt;info&;gt;" with escaped tags like
        // "\<info>".
        $value = preg_replace('@&lt;(/?[a-z][a-z0-9,_=;-]*+)&gt;@i', '\\\<$1>', $value);

        // Decode other HTML entities.
        $value = html_entity_decode($value, ENT_QUOTES, 'utf-8');

        return $value;
    }

    /**
     * @param Activity $activity
     *
     * @return false|int
     */
    private function getStart(Activity $activity) {
        return !empty($activity->started_at) ? strtotime($activity->started_at) : strtotime($activity->created_at);
    }
}
