<?php

namespace Platformsh\Cli\Model;

use Platformsh\Client\Model\Activity as ApiActivity;

class Activity {

    /**
     * Calculates the duration of an activity, whether complete or not.
     *
     * @param ApiActivity $activity
     * @param int|null    $now
     *
     * @return int|null
     */
    public function getDuration(ApiActivity $activity, $now = null)
    {
        if ($activity->isComplete()) {
            $end = strtotime($activity->completed_at);
        } elseif (!empty($activity->started_at)) {
            $now = $now === null ? time() : $now;
            $end = $now;
        } else {
            $end = strtotime($activity->updated_at);
        }
        $start = !empty($activity->started_at) ? strtotime($activity->started_at) : strtotime($activity->created_at);

        return $end !== false && $start !== false && $end - $start > 0 ? $end - $start : null;
    }
}
