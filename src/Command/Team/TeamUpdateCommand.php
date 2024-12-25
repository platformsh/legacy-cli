<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Team;

use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Util\Wildcard;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * This command is the same as team:create, with different documentation.
 */
#[AsCommand(name: 'team:update', description: 'Update a team')]
class TeamUpdateCommand extends TeamCreateCommand
{
    protected function configure(): void
    {
        $this->addOption('label', null, InputOption::VALUE_REQUIRED, 'Set a new team label')
            ->addOption('no-check-unique', null, InputOption::VALUE_NONE, 'Do not error if another team exists with the same label in the organization')
            ->addOption('role', 'r', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, "Set the team's project and environment type roles\n"
               . ArrayArgument::SPLIT_HELP . "\n" . Wildcard::HELP);
        $this->addTeamOption();
        $this->selector->addOrganizationOptions($this->getDefinition());
        $this->activityMonitor->addWaitOptions($this->getDefinition());
    }
}
