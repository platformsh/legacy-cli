<?php

namespace Platformsh\Cli\Command\Organization\User;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'organization:user:update', description: 'Update an organization user')]
class OrganizationUserUpdateCommand extends OrganizationUserAddCommand
{
    protected function configure()
    {
        $this
            ->addOrganizationOptions()
            ->addArgument('email', InputArgument::OPTIONAL, 'The email address of the user')
            ->addPermissionOption();
    }
}
