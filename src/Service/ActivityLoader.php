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
     * @param int         $limit
     * @param string|null $type
     * @param int|null    $startsAt
     *
     * @return \Platformsh\Client\Model\Activity[]
     */
    public function load(Resource $apiResource, $limit, $type, $startsAt)
    {
        /** @var \Platformsh\Client\Model\Environment|\Platformsh\Client\Model\Project $apiResource */
        $activities = $apiResource->getActivities($limit, $type, $startsAt);
        $progress = new ProgressBar($this->getProgressOutput());
        $progress->setMessage($type === 'environment.backup' ? 'Loading backups...' : 'Loading activities...');
        $progress->setFormat('%message% %current% (max: %max%)');
        $progress->start($limit);

        // Index the array by the activity ID for deduplication.
        $indexed = [];
        foreach ($activities as $activity) {
            $indexed[$activity->id] = $activity;
        }
        $activities = $indexed;
        unset($indexed);

        while (count($activities) < $limit) {
            if ($activity = end($activities)) {
                $startsAt = strtotime($activity->created_at);
            }
            $nextActivities = $apiResource->getActivities($limit - count($activities), $type, $startsAt);
            $new = false;
            foreach ($nextActivities as $activity) {
                if (!isset($activities[$activity->id])) {
                    $activities[$activity->id] = $activity;
                    $new = true;
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
