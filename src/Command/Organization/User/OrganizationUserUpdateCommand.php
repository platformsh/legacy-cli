<?php

namespace Platformsh\Cli\Command\Organization\User;

use Symfony\Component\Console\Input\InputArgument;

class OrganizationUserUpdateCommand extends OrganizationUserAddCommand
{
    protected function configure()
    {
        $this->setName('organization:user:update')
            ->setDescription('Update an organization user')
            ->addOrganizationOptions()
            ->addArgument('email', InputArgument::OPTIONAL, 'The email address of the user')
            ->addPermissionOption();
    }
}
