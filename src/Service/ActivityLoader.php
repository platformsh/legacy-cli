<?php

namespace Platformsh\Cli\Service;

use Platformsh\Client\Model\Resource;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityLoader
{

    private $output;

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
    private function getProgressOutput()
    {
        if (!$this->output->isDecorated()) {
            return new NullOutput();
        }
        if ($this->output instanceof ConsoleOutputInterface) {
            return $this->output->getErrorOutput();
        }

        return $this->output;
    }

    /**
     * Load activities.
     *
     * @param Resource    $apiResource
     * @param int|null    $limit
     * @param string|null $type
     * @param int|null    $startsAt
     * @param callable|null $stopCondition
     *   A test to perform on each activity. If it returns true, loading is stopped.
     *
     * @return \Platformsh\Client\Model\Activity[]
     */
    public function load(Resource $apiResource, $limit, $type, $startsAt, callable $stopCondition = null)
    {
        /** @var \Platformsh\Client\Model\Environment|\Platformsh\Client\Model\Project $apiResource */
        $activities = $apiResource->getActivities($limit ?: 0, $type, $startsAt);
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
            $nextActivities = $apiResource->getActivities($limit ? $limit - count($activities) : 0, $type, $startsAt);
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
