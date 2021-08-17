<?php

namespace Platformsh\Cli\Command\Organization;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Model\Organization\Member;

class OrganizationCommandBase extends CommandBase
{
    public function isEnabled()
    {
        if (!$this->config()->getWithDefault('api.organizations', false)) {
            return false;
        }
        return parent::isEnabled();
    }

    protected function memberLabel(Member $member)
    {
        if ($info = $member->getUserInfo()) {
            return $info->email;
        }

        return $member->id;
    }
}
