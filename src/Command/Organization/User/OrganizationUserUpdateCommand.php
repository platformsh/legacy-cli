<?php

namespace Platformsh\Cli\Command\Organization\User;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'organization:user:update', description: 'Update an organization user')]
class OrganizationUserUpdateCommand extends OrganizationUserAddCommand
{
    protected function configure(): void
    {
        $this->selector->addOrganizationOptions($this->getDefinition());
        $this->addArgument('email', InputArgument::OPTIONAL, 'The email address of the user')
            ->addPermissionOption();
    }
}
