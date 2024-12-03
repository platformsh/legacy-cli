<?php

namespace Platformsh\Cli\Command\Organization\User;

use Platformsh\Cli\Selector\Selector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'organization:user:update', description: 'Update an organization user')]
class OrganizationUserUpdateCommand extends OrganizationUserAddCommand
{
    public function __construct(private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure()
    {
        $this->selector->addOrganizationOptions($this->getDefinition())
            ->addArgument('email', InputArgument::OPTIONAL, 'The email address of the user')
            ->addPermissionOption();
    }
}
