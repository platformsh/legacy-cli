<?php

namespace Platformsh\Cli\Exception;

class NoOrganizationsException extends \Exception {
    private $totalNumOrgs;

    /**
     * @param string $message
     * @param int $totalNumOrgs
     * @param string $filteredByLink
     */
    public function __construct($message, $totalNumOrgs)
    {
        $this->totalNumOrgs = $totalNumOrgs;
        parent::__construct($message);
    }

    /**
     * @return int
     */
    public function getTotalNumOrgs()
    {
        return $this->totalNumOrgs;
    }
}
