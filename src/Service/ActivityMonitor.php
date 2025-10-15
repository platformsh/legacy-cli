<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Client\Model\Activity;
use Platformsh\Client\Model\ActivityLog\LogItem;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityMonitor
{
    public const STREAM_WAIT = 200000; // microseconds

    private const RESULT_NAMES = [
        Activity::RESULT_FAILURE => 'failure',
        Activity::RESULT_SUCCESS => 'success',
    ];

    private const STATE_NAMES = [
        Activity::STATE_PENDING => 'pending',
        Activity::STATE_COMPLETE => 'complete',
        Activity::STATE_IN_PROGRESS => 'in progress',
        Activity::STATE_CANCELLED => 'cancelled',
    ];

    private readonly OutputInterface $stdErr;

    public function __construct(private readonly Config $config, private readonly Api $api, private readonly Io $io, OutputInterface $output)
    {
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /**
     * Indents a multi-line string.
     */
    protected function indent(string $string, string $prefix = '    '): string
    {
        return preg_replace('/^/m', $prefix, $string);
    }

    /**
     * Add both the --no-wait and --wait options.
     */
    public function addWaitOptions(InputDefinition $definition): void
    {
        $definition->addOption(new InputOption('no-wait', 'W', InputOption::VALUE_NONE, 'Do not wait for the operation to complete'));
        if ($this->detectRunningInHook()) {
            $definition->addOption(new InputOption('wait', null, InputOption::VALUE_NONE, 'Wait for the operation to complete'));
        } else {
            $definition->addOption(new InputOption('wait', null, InputOption::VALUE_NONE, 'Wait for the operation to complete (default)'));
        }
    }

    /**
     * Returns whether we should wait for an operation to complete.
     */
    public function shouldWait(InputInterface $input): bool
    {
        if ($input->hasOption('no-wait') && $input->getOption('no-wait')) {
            return false;
        }
        if ($input->hasOption('wait') && $input->getOption('wait')) {
            return true;
        }
        if ($this->detectRunningInHook()) {
            $serviceName = $this->config->getStr('service.name');
            $message = "\n<comment>Warning:</comment> $serviceName hook environment detected: assuming <comment>--no-wait</comment> by default."
                . "\nTo avoid ambiguity, please specify either --no-wait or --wait."
                . "\n";
            $this->stdErr->writeln($message);

            return false;
        }

        return true;
    }

    /**
     * Detects a Platform.sh non-terminal Dash environment; i.e. a hook.
     *
     * @return bool
     */
    private function detectRunningInHook(): bool
    {
        $envPrefix = $this->config->getStr('service.env_prefix');
        if (getenv($envPrefix . 'PROJECT')
            && basename((string) getenv('SHELL')) === 'dash'
            && !$this->io->isTerminal(STDIN)) {
            return true;
        }

        return false;
    }

    /**
     * Wait for a single activity to complete, and display the log continuously.
     *
     * @param Activity $activity The activity.
     * @param int $pollInterval The interval between refreshing the activity (seconds).
     * @param bool|string $timestamps Whether to display timestamps (or pass in a date format).
     * @param bool $context Whether to add a context message.
     * @param OutputInterface|null $logOutput The output object for log messages (defaults to stderr).
     * @param bool $noResult Whether to suppress the activity result.
     *
     * @return bool True if the activity succeeded, false otherwise.
     */
    public function waitAndLog(Activity $activity, int $pollInterval = 3, bool|string $timestamps = false, bool $context = true, ?OutputInterface $logOutput = null, bool $noResult = false): bool
    {
        $stdErr = $this->stdErr;
        $logOutput = $logOutput ?: $stdErr;

        if ($context) {
            $stdErr->writeln('');
            $stdErr->writeln('Waiting for the activity: ' . self::getFormattedDescription($activity, true, true, 'cyan'));
            $stdErr->writeln('');
        }

        // The progress bar will show elapsed time and the activity's state.
        $bar = $this->newProgressBar($stdErr);
        $overrideState = '';
        $progressColor = 'cyan';
        $bar->setPlaceholderFormatterDefinition('state', function () use ($activity, &$overrideState) {
            return $this->formatState($overrideState ?: $activity->state);
        });
        $startTime = $this->getStart($activity) ?: time();
        $bar->setPlaceholderFormatterDefinition('elapsed', fn() => $this->formatDuration(time() - $startTime));
        $bar->setPlaceholderFormatterDefinition('fgColor', function () use (&$progressColor): string { return $progressColor; });
        $bar->setFormat('[%bar%] <fg=%fgColor%>%elapsed:6s%</> (%state%)');
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
                if ($activity->isComplete() || $activity->state === Activity::STATE_CANCELLED
                    || $activity->state === Activity::STATE_STAGED) {
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

        if ($activity->result === Activity::RESULT_FAILURE) {
            if ($activity->state === Activity::STATE_CANCELLED) {
                $progressColor = 'yellow';
            } else {
                $progressColor = 'red';
            }
        } elseif ($activity->result === Activity::RESULT_SUCCESS) {
            $progressColor = 'green';
        } else {
            $progressColor = 'yellow';
        }

        $bar->finish();
        $stdErr->writeln('');

        if (!$noResult) {
            $stdErr->writeln('');
            $this->printResult($activity);
        }

        return $activity->result === Activity::RESULT_SUCCESS;
    }

    /**
     * Formats a duration in seconds.
     *
     * @param int|float $value
     *
     * @return string
     */
    private function formatDuration(int|float $value): string
    {
        $hours = $minutes = 0;
        $seconds = (int) round($value);
        if ($seconds >= 3600) {
            $hours = floor($seconds / 3600);
            $seconds %= 3600;
        }
        if ($seconds >= 60) {
            $minutes = floor($seconds / 60);
            $seconds %= 60;
        }
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    /**
     * Reads the log stream and returns LogItem objects.
     *
     * @param string &$buffer
     *   A buffer containing recent data from the stream.
     *
     * @return array{'items': LogItem[], 'seal': bool}
     */
    private function parseLog(string &$buffer): array
    {
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
    public function formatLog(array $items, bool|string $timestamps = false): string
    {
        $timestampFormat = false;
        if ($timestamps !== false) {
            $timestampFormat = $timestamps === true ? $this->config->getStr('application.date_format') : $timestamps;
        }
        $formatItem = function (LogItem $item) use ($timestampFormat): string {
            if ($timestampFormat !== false) {
                return '[' . $item->getTime()->format($timestampFormat) . '] ' . $item->getMessage();
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
     * @param Activity[] $activities
     * @param Project $project
     * @param bool $context Display the activity names before waiting.
     * @param bool $noLog Don't display the log even if there is only 1 activity.
     * @param bool $noResult Don't display the activity result.
     *
     * @return bool
     *   True if all activities succeed, false otherwise.
     */
    public function waitMultiple(array $activities, Project $project, bool $context = true, bool $noLog = false, bool $noResult = false): bool
    {
        $stdErr = $this->stdErr;

        // If there is 1 activity then display its log.
        $count = count($activities);
        if ($count == 0) {
            return true;
        } elseif ($count === 1 && !$noLog) {
            return $this->waitAndLog(reset($activities), 3, false, $context, $stdErr);
        }

        // Split integration and non-integration activities, and put the latter first.
        $integrationActivities = array_filter($activities, fn(Activity $a): bool => str_starts_with($a->type, 'integration.'));
        $nonIntegrationActivities = array_filter($activities, fn(Activity $a): bool => !str_starts_with($a->type, 'integration.'));
        $activities = array_merge($nonIntegrationActivities, $integrationActivities);

        // For more than one activity, output a list of their descriptions.
        if ($context) {
            $stdErr->writeln('');
            $stdErr->writeln(sprintf('Waiting for %d activities:', $count));
            foreach ($activities as $i => $activity) {
                $stdErr->writeln(sprintf('  <fg=cyan>#%d</> %s', $i + 1, self::getFormattedDescription($activity, true, true, 'cyan')));
            }
        }

        // If there is 1 non-integration activity, then display its log, and
        // wait for the integration activities separately.
        //
        // This is because user actions tend to return a single activity,
        // alongside one or more integration activities. The log of the single
        // activity is more likely to be immediately useful.
        if (count($nonIntegrationActivities) === 1) {
            $nonIntegrationActivity = reset($nonIntegrationActivities);
            $stdErr->writeln('');
            $stdErr->writeln(sprintf('<fg=cyan>#%d</> %s:', 1, self::getFormattedDescription($nonIntegrationActivity, true, true, 'cyan')));
            $stdErr->writeln('');
            $nonIntegrationSuccess = $this->waitAndLog($nonIntegrationActivity, 3, false, false, $stdErr, true);
            $stdErr->writeln('');
            $i = 1;
            foreach ($integrationActivities as $integrationActivity) {
                $stdErr->writeln(sprintf('<fg=cyan>#%d</> %s:', $i + 1, self::getFormattedDescription($integrationActivity, true, true, 'cyan')));
                $i++;
            }
            $stdErr->writeln('');
            $integrationSuccess = $this->waitMultiple($integrationActivities, $project, false, true, true);
            $stdErr->writeln('');
            // Display success or failure messages for each activity.
            $byResult = [Activity::RESULT_SUCCESS => [], 'cancelled' => [], Activity::RESULT_FAILURE => []];
            foreach ($activities as $i => $activity) {
                $result = $activity->result;
                if ($activity->state === Activity::STATE_CANCELLED) {
                    $result = 'cancelled';
                }
                $byResult[$result][] = [$i + 1, $activity];
            }
            foreach ($byResult as $result => $items) {
                if (empty($items)) {
                    continue;
                }
                $showLog = false;
                $summaryCount = count($activities) === 1 ? 'The activity' : sprintf('%d/%d activities', count($items), count($activities));
                switch ($result) {
                    case 'cancelled':
                        $fgColor = 'yellow';
                        $stdErr->writeln(sprintf('%s %s <fg=%s>cancelled</>:', $summaryCount, count($activities) === 1 ? 'was' : 'were', $fgColor));
                        break;
                    case Activity::RESULT_SUCCESS:
                        $fgColor = 'green';
                        $stdErr->writeln(sprintf('%s <fg=%s>succeeded</>:', $summaryCount, $fgColor));
                        break;
                    case Activity::RESULT_FAILURE:
                        $fgColor = 'red';
                        $stdErr->writeln(sprintf('%s <fg=%s>failed</>:', $summaryCount, $fgColor));
                        $showLog = true;
                        break;
                    default:
                        $fgColor = 'red';
                        $showLog = true;
                        $stdErr->writeln(sprintf('%s finished with an <fg=%s>unknown result</>:', $summaryCount, $fgColor));
                }
                foreach ($items as $item) {
                    [$num, $activity] = $item;
                    $stdErr->writeln(sprintf('  <fg=%s>#%d</> %s', $fgColor, $num, self::getFormattedDescription($activity, true, true, $fgColor)));
                    if ($showLog) {
                        $stdErr->writeln('  <error>Log:</error>');
                        $stdErr->writeln($this->indent($this->formatLog($activity->readLog())));
                    }
                }
            }
            return $nonIntegrationSuccess && $integrationSuccess;
        }

        // The progress bar will show elapsed time and all of the activities'
        // states.
        $bar = $this->newProgressBar($stdErr);
        $states = [];
        $progressColor = 'cyan';
        $startTime = time();
        foreach ($activities as $activity) {
            $state = $activity->state;
            $states[$state] = isset($states[$state]) ? $states[$state] + 1 : 1;
            if (($activityStart = $this->getStart($activity)) && $activityStart < $startTime) {
                $startTime = $activityStart;
            }
        }
        $bar->setFormat('[%bar%] <fg=%fgColor%>%elapsed:6s%</> (%states%)');
        $bar->setPlaceholderFormatterDefinition('states', function () use (&$states) {
            if (count($states) === 1) {
                return $this->formatState(key($states));
            }
            $withCount = [];
            foreach ($states as $state => $count) {
                $withCount[] = $count . ' ' . $this->formatState($state);
            }
            return implode(', ', $withCount);
        });
        $bar->setPlaceholderFormatterDefinition('fgColor', function () use (&$progressColor): string { return $progressColor; });
        $bar->setPlaceholderFormatterDefinition('elapsed', fn() => $this->formatDuration(time() - $startTime));
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

        $success = true;
        foreach ($activities as $activity) {
            $success = $activity->result === Activity::RESULT_SUCCESS && $success;
            if ($activity->result === Activity::RESULT_FAILURE) {
                if ($activity->state === Activity::STATE_CANCELLED && $progressColor !== 'red') {
                    $progressColor = 'yellow';
                    continue;
                }
                $progressColor = 'red';
            } elseif ($progressColor !== 'red' && $activity->result === Activity::RESULT_SUCCESS) {
                $progressColor = 'green';
            } elseif ($progressColor !== 'red') {
                $progressColor = 'yellow';
            }
        }

        $bar->finish();
        $stdErr->writeln('');

        // Display success or failure messages for each activity.
        if (!$noResult) {
            $stdErr->writeln('');
            foreach ($activities as $activity) {
                $this->printResult($activity, true);
            }
        }

        return $success;
    }

    /**
     * Prints the result of an activity: success, failure, or cancelled.
     */
    private function printResult(Activity $activity, bool $logOnFailure = false): void
    {
        $stdErr = $this->stdErr;

        // Display the success or failure messages.
        switch ($activity->result) {
            case Activity::RESULT_SUCCESS:
                $stdErr->writeln('The activity succeeded: ' . self::getFormattedDescription($activity, true, true, 'green'));
                break;
            case Activity::RESULT_FAILURE:
                if ($activity->state === Activity::STATE_CANCELLED) {
                    $stdErr->writeln('The activity was cancelled: ' . self::getFormattedDescription($activity, true, true, 'yellow'));
                    break;
                }
                $stdErr->writeln('The activity failed: ' . self::getFormattedDescription($activity, true, true, 'red'));
                if ($logOnFailure) {
                    $stdErr->writeln('  <error>Log:</error>');
                    $stdErr->writeln($this->indent($this->formatLog($activity->readLog())));
                }
                break;
            default:
                $stdErr->writeln('The activity finished with an unknown result: ' . self::getFormattedDescription($activity, true, true, 'yellow'));
        }

        if ($activity->state === Activity::STATE_STAGED) {
            $stdErr->writeln(sprintf(
                'To deploy staged changes, run: <info>%s env:deploy</info>',
                $this->config->getStr('application.executable'),
            ));
        }
    }

    /**
     * Formats a state name.
     */
    public static function formatState(string $state): string
    {
        return self::STATE_NAMES[$state] ?? $state;
    }

    /**
     * Formats an activity result.
     */
    public static function formatResult(Activity $activity, bool $decorate = true): string
    {
        $result = $activity->result;
        $name = self::RESULT_NAMES[$result] ?? $result;

        foreach ($activity->commands ?? [] as $command) {
            if ($command['exit_code'] > 0) {
                $name = Activity::RESULT_FAILURE;
                $result = Activity::RESULT_FAILURE;
                break;
            }
        }

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
    protected function newProgressBar(OutputInterface $output): ProgressBar
    {
        // If the console output is not decorated (i.e. it does not support
        // ANSI), use NullOutput to suppress the progress bar entirely.
        $progressOutput = $output->isDecorated() ? $output : new NullOutput();

        return new ProgressBar($progressOutput);
    }

    /**
     * Get the formatted description of an activity.
     *
     * @param Activity $activity The activity.
     * @param bool $withDecoration Add decoration to activity tags.
     * @param bool $withId Add the activity ID.
     * @param string $fgColor Define a foreground color e.g. 'green', 'red', 'cyan'.
     *
     * @return string
     */
    public static function getFormattedDescription(Activity $activity, bool $withDecoration = true, bool $withId = false, string $fgColor = ''): string
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
        $descr = preg_replace('@</[^>]+>@', '</>', (string) $descr);

        // Replace literal tags like "&lt;info&;gt;" with escaped tags like
        // "\<info>".
        $descr = preg_replace('@&lt;(/?[a-z][a-z0-9,_=;-]*+)&gt;@i', '\\\<$1>', (string) $descr);

        // Decode other HTML entities.
        $descr = html_entity_decode((string) $descr, ENT_QUOTES, 'utf-8');

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
    private function getStart(Activity $activity): int|false
    {
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
    private function getLogStream(Activity $activity, ProgressBar $bar)
    {
        $url = $activity->getLink('log');

        // Try fetching the stream with a 10 second timeout per call, and a .5
        // second interval between calls, for up to 2 minutes.
        $readTimeout = 10;
        $interval = .5;

        if ($this->config->getBool('api.debug')) {
            $bar->clear();
            $stdErr = $this->stdErr;
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
            \usleep((int) $interval * 1000000);
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
