<?php

namespace Platformsh\Cli\Service;

use Platformsh\Client\Model\Activities\HasActivitiesInterface;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityLoader
{

    private $stdErr;

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    public function __construct(OutputInterface $output)
    {
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /**
     * @return \Symfony\Component\Console\Output\OutputInterface
     */
    private function getProgressOutput()
    {
        return $this->stdErr->isDecorated() ? $this->stdErr : new NullOutput();
    }

    /**
     * Loads activities, looking at options set in a command input.
     *
     * The --state, --incomplete, --result, --start, --limit, and --type options will be processed.
     *
     * @param int|null $limit Limit the number of activities to return, regardless of input.
     *
     * @return \Platformsh\Client\Model\Activity[]|false
     *   False if an error occurred, an array of activities otherwise.
     */
    public function loadFromInput(HasActivitiesInterface $apiResource, InputInterface $input, $limit = null)
    {
        $state = [];
        if ($input->hasOption('state')) {
            $state = $input->getOption('state');
            if (\count($state) === 1) {
                $state = \array_filter(\preg_split('/[,\s]+/', \reset($state)), '\\strlen');
            }
            if ($input->getOption('incomplete')) {
                if ($state && $state != [Activity::STATE_IN_PROGRESS, Activity::STATE_PENDING]) {
                    $this->stdErr->writeln('The <comment>--incomplete</comment> option implies <comment>--state in_progress,pending</comment>');
                }
                $state = [Activity::STATE_IN_PROGRESS, Activity::STATE_PENDING];
            }
        }
        if ($limit === null) {
            $limit = $input->hasOption('limit') ? $input->getOption('limit') : null;
        }
        $type = $input->hasOption('type') ? $input->getOption('type') : null;
        $result = $input->hasOption('result') ? $input->getOption('result') : null;
        $startsAt = null;
        if ($input->hasOption('start') && $input->getOption('start') && !($startsAt = strtotime($input->getOption('start')))) {
            $this->stdErr->writeln('Invalid --start date: <error>' . $input->getOption('start') . '</error>');
            return [];
        }
        return $this->load($apiResource, $limit, $type, $startsAt, $state, $result);
    }

    /**
     * Loads activities.
     *
     * @param HasActivitiesInterface    $apiResource
     * @param int|null    $limit
     * @param string|null $type
     * @param int|null    $startsAt
     * @param string|string[]|null    $state
     * @param string|string[]|null    $result
     * @param callable|null $stopCondition
     *   A test to perform on each activity. If it returns true, loading is stopped.
     *
     * @return \Platformsh\Client\Model\Activity[]
     */
    public function load(HasActivitiesInterface $apiResource, $limit = null, $type = null, $startsAt = null, $state = null, $result = null, callable $stopCondition = null)
    {
        /** @var \Platformsh\Client\Model\Environment|\Platformsh\Client\Model\Project $apiResource */
        $activities = $apiResource->getActivities($limit ?: 0, $type, $startsAt, $state, $result);
        $progress = new ProgressBar($this->getProgressOutput());
        $progress->setMessage($type === 'environment.backup' ? 'Loading backups...' : 'Loading activities...');
        $progress->setFormat($limit === null ? '%message% %current%' : '%message% %current% (max: %max%)');
        $progress->start($limit);

        // Index the array by the activity ID for deduplication.
        $indexed = [];
        foreach ($activities as $activity) {
            $indexed[$activity->id] = $activity;
        }
        $activities = $indexed;
        unset($indexed);

        while ($limit === null || count($activities) < $limit) {
            if ($activity = end($activities)) {
                $startsAt = strtotime($activity->created_at);
            }
            $nextActivities = $apiResource->getActivities($limit ? $limit - count($activities) : 0, $type, $startsAt, $state, $result);
            $new = false;
            foreach ($nextActivities as $activity) {
                if (!isset($activities[$activity->id])) {
                    $activities[$activity->id] = $activity;
                    $new = true;
                    if (isset($stopCondition) && $stopCondition($activity)) {
                        $progress->setProgress(count($activities));
                        break 2;
                    }
                }
            }
            if (!$new) {
                break;
            }
            $progress->setProgress(count($activities));
        }
        $progress->clear();

        return array_values($activities);
    }
}
