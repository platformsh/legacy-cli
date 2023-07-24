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
    const STREAM_WAIT = 200000; // microseconds

    protected static $resultNames = [
        Activity::RESULT_FAILURE => 'failure',
        Activity::RESULT_SUCCESS => 'success',
    ];

    protected static $stateNames = [
        Activity::STATE_PENDING => 'pending',
        Activity::STATE_COMPLETE => 'complete',
        Activity::STATE_IN_PROGRESS => 'in progress',
        Activity::STATE_CANCELLED => 'cancelled',
    ];

    protected $output;
    protected $config;
    protected $api;

    /**
     * @param OutputInterface $output
     * @param Config $config
     * @param Api $api
     */
    public function __construct(OutputInterface $output, Config $config, Api $api)
    {
        $this->output = $output;
        $this->config = $config;
        $this->api = $api;
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
     * @param Activity $activity The activity.
     * @param int $pollInterval The interval between refreshing the activity (seconds).
     * @param bool|string $timestamps Whether to display timestamps (or pass in a date format).
     * @param bool $context Whether to add a context message.
     * @param OutputInterface|null $logOutput The output object for log messages (defaults to stderr).
     *
     * @return bool True if the activity succeeded, false otherwise.
     */
    public function waitAndLog(Activity $activity, $pollInterval = 3, $timestamps = false, $context = true, OutputInterface $logOutput = null)
    {
        $stdErr = $this->getStdErr();
        $logOutput = $logOutput ?: $stdErr;

        if ($context) {
            $stdErr->writeln('');
            $stdErr->writeln('Waiting for the activity: ' . self::getFormattedDescription($activity, true, true, 'cyan'));
            $stdErr->writeln('');
        }

        // The progress bar will show elapsed time and the activity's state.
        $bar = $this->newProgressBar($stdErr);
        $overrideState = '';
        $bar->setPlaceholderFormatterDefinition('state', function () use ($activity, &$overrideState) {
            return $this->formatState($overrideState ?: $activity->state);
        });
        $startTime = $this->getStart($activity) ?: time();
        $bar->setPlaceholderFormatterDefinition('elapsed', function () use ($startTime) {
            return Helper::formatTime(time() - $startTime);
        });
        $bar->setFormat('[%bar%] %elapsed:6s% (%state%)');
        $bar->start();

        $logStream = $this->getLogStream($activity, $bar);
        $lastLogFetch = microtime(true);
        $bar->advance();

        // Read the log while waiting for the activity to complete.
        $lastRefresh = microtime(true);
        $buffer = '';
        $seal = false;
        $itemIds = [];
        while (true) {
            // If $pollInterval has passed, or if the stream has ended, then
            // refresh the activity.
            if ($seal || microtime(true) - $lastRefresh >= $pollInterval) {
                $activity->refresh();
                $overrideState = '';
                $lastRefresh = microtime(true);
            }

            // Exit the loop if the log finished and the activity is complete.
            if ($seal) {
                if ($activity->isComplete() || $activity->state === Activity::STATE_CANCELLED) {
                    break;
                }
                continue;
            }

            $bar->advance();

            // Re-fetch the log if it reached EOF or errored before receiving
            // the "seal".
            if (\feof($logStream)) {
                $buffer = '';
                // Limit the frequency of re-fetching the log.
                if (microtime(true) - $lastLogFetch < .3) {
                    \usleep(300000);
                    $bar->advance();
                }
                $logStream = $this->getLogStream($activity, $bar);
                $lastLogFetch = microtime(true);
                $bar->advance();
                continue;
            }

            // Read up to 8 KiB of new content from the stream.
            // This will return a string when a packet is available, or false
            // when the stream timeout is reached, or on EOF.
            $content = \fread($logStream, 8192);
            if ($content === '') {
                \usleep(self::STREAM_WAIT);
                continue;
            } elseif ($content === false) {
                // This indicates a stream timeout or EOF.
                continue;
            } else {
                $buffer .= $content;
            }

            // Parse the log.
            $data = $this->parseLog($buffer);
            if ($data['seal']) {
                $seal = true;
            }

            // Deduplicate already seen log items.
            $items = $data['items'];
            foreach ($items as $key => $item) {
                $id = $item->getId();
                if ($id !== '') {
                    if (isset($itemIds[$id])) {
                        unset($items[$key]);
                    } else {
                        $itemIds[$id] = true;
                    }
                }
            }

            if (empty($items)) {
                if (!$seal) {
                    \usleep(self::STREAM_WAIT);
                }
                continue;
            }

            // If there is log output, assume the activity must be in progress.
            if ($activity->state === Activity::STATE_PENDING) {
                $overrideState = Activity::STATE_IN_PROGRESS;
            }

            // Format log items.
            $formatted = $this->formatLog($data['items'], $timestamps);

            // Clear the progress bar and ensure the current line is flushed.
            $bar->clear();
            $stdErr->write($stdErr->isDecorated() ? "\n\033[1A" : "\n");

            // Display the new log output.
            $logOutput->write($formatted);

            // Display the progress bar again.
            $bar->advance();
        }
        $bar->finish();
        $stdErr->writeln('');
        $stdErr->writeln('');

        // Display the success or failure messages.
        switch ($activity->result) {
            case Activity::RESULT_SUCCESS:
                $stdErr->writeln('The activity succeeded: ' . self::getFormattedDescription($activity, true, true, 'green'));
                return true;

            case Activity::RESULT_FAILURE:
                if ($activity->state === Activity::STATE_CANCELLED) {
                    $stdErr->writeln('The activity was cancelled: ' . self::getFormattedDescription($activity, true, true, 'yellow'));
                } else {
                    $stdErr->writeln('The activity failed: ' . self::getFormattedDescription($activity, true, true, 'red'));
                }
                return false;
        }

        $stdErr->writeln('The activity finished with an unknown result: ' . self::getFormattedDescription($activity, true, true, 'yellow'));

        return false;
    }

    /**
     * Reads the log stream and returns LogItem objects.
     *
     * @param string &$buffer
     *   A buffer containing recent data from the stream.
     *
     * @return array{'items': LogItem[], 'seal': bool}
     */
    private function parseLog(&$buffer) {
        if (\strlen($buffer) <= 1) {
            return ['items' => [], 'seal' => false];
        }
        $lastNewline = strrpos($buffer, "\n");
        if ($lastNewline === false) {
            return ['items' => [], 'seal' => false];
        }
        $content = substr($buffer, 0, $lastNewline + 1);
        $buffer = substr($buffer, $lastNewline + 1);

        return LogItem::multipleFromJsonStreamWithSeal($content);
    }

    /**
     * Formats log items for display.
     *
     * @param LogItem[]   $items
     *   The log items.
     * @param bool|string $timestamps
     *   False for no timestamps, or a string date format or true to display timestamps
     *
     * @return string
     */
    public function formatLog(array $items, $timestamps = false) {
        $timestampFormat = false;
        if ($timestamps !== false) {
            $timestampFormat = $timestamps ?: $this->config->getWithDefault('application.date_format', 'Y-m-d H:i:s');
        }
        $formatItem = function (LogItem $item) use ($timestampFormat) {
            if ($timestampFormat !== false) {
                return '[' . $item->getTime()->format($timestampFormat) . '] '. $item->getMessage();
            }

            return $item->getMessage();
        };

        return implode('', array_map($formatItem, $items));
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

        // If there is 1 activity then display its log.
        $count = count($activities);
        if ($count == 0) {
            return true;
        } elseif ($count === 1) {
            return $this->waitAndLog(reset($activities));
        }

        // If there is 1 non-integration activity, then display its log, and
        // wait for the integration activities separately.
        $nonIntegrationActivities = array_filter($activities, function (Activity $a) {
            return strpos($a->type, 'integration.') !== 0;
        });
        if (count($nonIntegrationActivities) === 1) {
            $nonIntegrationActivity = reset($nonIntegrationActivities);
            $integrationActivities = array_filter($activities, function (Activity $a) {
                return strpos($a->type, 'integration.') === 0;
            });
            $nonIntegrationSuccess = $this->waitAndLog($nonIntegrationActivity);
            $integrationSuccess = $this->waitMultiple($integrationActivities, $project);
            return $nonIntegrationSuccess && $integrationSuccess;
        }

        // For more than one activity, display a progress bar with the status of each.
        $stdErr->writeln(sprintf('Waiting for %d activities...', $count));
        foreach ($activities as $activity) {
            $stdErr->writeln('  ', self::getFormattedDescription($activity, true, true, 'cyan'));
        }

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

        // Wait for the activities to be completed or cancelled, polling
        // (refreshing) all of them with a one-second delay.
        $done = 0;
        while ($done < $count) {
            sleep(1);
            $states = [];
            $done = 0;
            // Get a list of activities on the project. Any of our activities
            // which are not contained in this list must be refreshed
            // individually.
            $projectActivities = $project->getActivities(0, null, $mostRecentTimestamp ?: null);
            foreach ($activities as &$activityRef) {
                $refreshed = false;
                foreach ($projectActivities as $projectActivity) {
                    if ($projectActivity->id === $activityRef->id) {
                        $activityRef = $projectActivity;
                        $refreshed = true;
                        break;
                    }
                }
                if (!$refreshed && !$activityRef->isComplete() && $activityRef->state !== Activity::STATE_CANCELLED) {
                    $activityRef->refresh();
                }
                if ($activityRef->isComplete() || $activityRef->state === Activity::STATE_CANCELLED) {
                    $done++;
                }
                $state = $activityRef->state;
                $states[$state] = isset($states[$state]) ? $states[$state] + 1 : 1;
            }
            $bar->advance();
        }
        $bar->finish();
        $stdErr->writeln('');

        // Display success or failure messages for each activity.
        $success = true;
        foreach ($activities as $activity) {
            switch ($activity['result']) {
                case Activity::RESULT_SUCCESS:
                    $stdErr->writeln('Activity succeeded: ' . self::getFormattedDescription($activity, true, true, 'green'));
                    break;

                case Activity::RESULT_FAILURE:
                    $success = false;
                    $stdErr->writeln(sprintf('Activity failed: <error>%s</error>', $activity->id));

                    // If the activity failed, show the complete log.
                    $stdErr->writeln('  <error>Description:</error> ' . self::getFormattedDescription($activity));
                    $stdErr->writeln('  <error>Log:</error>');
                    $stdErr->writeln($this->indent($this->formatLog($activity->readLog())));
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
        $name = isset(self::$resultNames[$result]) ? self::$resultNames[$result] : $result;

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
     * @param \Platformsh\Client\Model\Activity $activity The activity.
     * @param bool $withDecoration Add decoration to activity tags.
     * @param bool $withId Add the activity ID.
     * @param string $fgColor Define a foreground color e.g. 'green', 'red', 'cyan'.
     *
     * @return string
     */
    public static function getFormattedDescription(Activity $activity, $withDecoration = true, $withId = false, $fgColor = '')
    {
        if (!$withDecoration) {
            if ($withId) {
                return '[' . $activity->id . '] ' . $activity->getDescription(false);
            }
            return $activity->getDescription(false);
        }
        $descr = $activity->getDescription(true);

        // Replace description HTML elements with Symfony Console decoration
        // tags.
        $descr = preg_replace('@<[^/][^>]+>@', '<options=underscore>', $descr);
        $descr = preg_replace('@</[^>]+>@', '</>', $descr);

        // Replace literal tags like "&lt;info&;gt;" with escaped tags like
        // "\<info>".
        $descr = preg_replace('@&lt;(/?[a-z][a-z0-9,_=;-]*+)&gt;@i', '\\\<$1>', $descr);

        // Decode other HTML entities.
        $descr = html_entity_decode($descr, ENT_QUOTES, 'utf-8');

        if ($withId) {
            if ($fgColor) {
                return sprintf('<fg=%s>[%s]</> %s', $fgColor, $activity->id, $descr);
            }
            return sprintf('[%s] %s', $activity->id, $descr);
        }

        return $descr;
    }

    /**
     * @param Activity $activity
     *
     * @return false|int
     */
    private function getStart(Activity $activity) {
        return !empty($activity->started_at) ? strtotime($activity->started_at) : strtotime($activity->created_at);
    }

    /**
     * Returns the activity log as a PHP stream resource.
     *
     * @param Activity $activity
     * @param ProgressBar $bar
     *   Progress bar, updated when we retry.
     *
     * @return resource
     */
    private function getLogStream(Activity $activity, ProgressBar $bar) {
        $url = $activity->getLink('log');

        // Try fetching the stream with a 10 second timeout per call, and a .5
        // second interval between calls, for up to 2 minutes.
        $readTimeout = 10;
        $interval = .5;

        if ($this->config->get('api.debug')) {
            $bar->clear();
            $stdErr = $this->getStdErr();
            $stdErr->write($stdErr->isDecorated() ? "\n\033[1A" : "\n");
            $stdErr->writeln('<options=reverse>DEBUG</> Fetching stream: ' . $url);
            $bar->display();
        }

        $stream = \fopen($url, 'r', false, $this->api->getStreamContext($readTimeout));
        $start = \microtime(true);
        while ($stream === false) {
            if (\microtime(true) - $start > 120) {
                throw new \RuntimeException('Failed to open activity log stream: ' . $url);
            }
            $bar->advance();
            \usleep($interval * 1000000);
            $bar->advance();
            $stream = \fopen($url, 'r', false, $this->api->getStreamContext($readTimeout));
        }
        if (!\stream_set_blocking($stream, false)) {
            \trigger_error('Failed to set stream to non-blocking', E_USER_WARNING);
        }
        if (!\stream_set_timeout($stream, 0, self::STREAM_WAIT)) {
            \trigger_error('Failed to set stream timeout', E_USER_WARNING);
        }

        return $stream;
    }
}
