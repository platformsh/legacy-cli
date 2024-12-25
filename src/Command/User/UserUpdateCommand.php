<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\User;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

/**
 * This command is the same as user:add, with different documentation.
 */
#[AsCommand(name: 'user:update', description: 'Update user role(s) on a project')]
class UserUpdateCommand extends UserAddCommand
{
    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::OPTIONAL, "The user's email address");

        $this->addRoleOption();
        $this->selector->addProjectOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->activityMonitor->addWaitOptions($this->getDefinition());

        $this->addExample('Make Bob an admin on the "development" and "staging" environment types', 'bob@example.com -r development:a,staging:a');
        $this->addExample('Make Charlie a contributor on all environment types', 'charlie@example.com -r %:c');
    }
}
