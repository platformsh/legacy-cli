<?php
namespace Platformsh\Cli\Command\User;

use Symfony\Component\Console\Input\InputArgument;

/**
 * This command is the same as user:add, with different documentation.
 */
class UserUpdateCommand extends UserAddCommand
{
    protected static $defaultName = 'user:update';
    protected static $defaultDescription = 'Update user role(s) on a project';

    protected function configure()
    {
        $this->addArgument('email', InputArgument::OPTIONAL, "The user's email address");

        $this->addRoleOption();
        $this->selector->addProjectOption($this->getDefinition());
        $this->activityService->configureInput($this->getDefinition());

        $this->addExample('Make Bob an admin on the "development" and "staging" environment types', 'bob@example.com -r development:a,staging:a');
        $this->addExample('Make Charlie a contributor on all environment types', 'charlie@example.com -r %:c');
    }
}
