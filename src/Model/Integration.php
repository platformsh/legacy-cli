<?php
namespace CommerceGuys\Platform\Cli\Model;

class Integration extends HalResource
{

    /**
     * @param array $data
     *
     * @return string
     */
    public static function formatData(array $data)
    {
        $output = '';
        if ($data['type'] == 'github') {
            $output = "Repository: " . $data['repository']
              . "\nBuild PRs: " . ($data['build_pull_requests'] ? 'yes' : 'no')
              . "\nFetch branches: " . ($data['fetch_branches'] ? 'yes' : 'no');
        }
        elseif ($data['type'] == 'hipchat') {
            $output = "Room ID: " . $data['room']
              . "\nEvents: " . implode(', ', $data['events'])
              . "\nStates: " . implode(', ', $data['states']);
        }
        return $output;
    }

}
