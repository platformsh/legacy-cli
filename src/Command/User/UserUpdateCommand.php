<?php
namespace Platformsh\Cli\Command\User;

use Symfony\Component\Console\Input\InputArgument;

/**
 * This command is the same as user:add, with different documentation.
 */
class UserUpdateCommand extends UserAddCommand
{

    protected function configure()
    {
        $this
            ->setName('user:update')
            ->setDescription('Update user role(s) on a project')
            ->addArgument('email', InputArgument::OPTIONAL, "The user's email address");

        $this->addRoleOption();
        $this->addProjectOption();
        $this->addWaitOptions();

        $this->addExample('Make Bob an admin on the "development" and "staging" environment types', 'bob@example.com -r development:a,staging:a');
        $this->addExample('Make Charlie a contributor on all environment types', 'charlie@example.com -r %:c');
    }
}
