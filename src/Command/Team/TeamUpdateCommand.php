<?php
namespace Platformsh\Cli\Command\Team;

use Platformsh\Cli\Selector\Selector;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * This command is the same as team:create, with different documentation.
 */
#[AsCommand(name: 'team:update', description: 'Update a team')]
class TeamUpdateCommand extends TeamCreateCommand
{

    public function __construct(private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure()
    {
        $this->selector->addTeamOption($this->getDefinition())
            ->addOrganizationOptions($this->getDefinition())
            ->addWaitOptions();
    }
}
