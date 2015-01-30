<?php
namespace CommerceGuys\Platform\Cli\Model;

class Integration extends HalResource
{

    /**
     * @return string
     */
    public function formatData()
    {
        $output = '';
        if ($this->data['type'] == 'github') {
            $output = "Repository: " . $this->data['repository']
              . "\nBuild PRs: " . ($this->data['build_pull_requests'] ? 'yes' : 'no')
              . "\nFetch branches: " . ($this->data['fetch_branches'] ? 'yes' : 'no')
              . "\nPayload URL: " . $this->getLink('#hook', true);
        }
        elseif ($this->data['type'] == 'hipchat') {
            $output = "Room ID: " . $this->data['room']
              . "\nEvents: " . implode(', ', $this->data['events'])
              . "\nStates: " . implode(', ', $this->data['states']);
        }
        return $output;
    }

}
