<?php

declare(strict_types=1);

namespace Platformsh\Cli\Exception;

class NoOrganizationsException extends \Exception
{
    public function __construct(string $message, private readonly int $totalNumOrgs)
    {
        parent::__construct($message);
    }

    public function getTotalNumOrgs(): int
    {
        return $this->totalNumOrgs;
    }
}
