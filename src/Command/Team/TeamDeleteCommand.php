<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Team;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\QuestionHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'team:delete', description: 'Delete a team')]
class TeamDeleteCommand extends TeamCommandBase
{
    public function __construct(private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->selector->addOrganizationOptions($this->getDefinition());
        $this->addTeamOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $team = $this->validateTeamInput($input);
        if (!$team) {
            return 1;
        }

        if (!$this->questionHelper->confirm(\sprintf('Are you sure you want to delete the team %s?', $this->getTeamLabel($team, 'comment')), false)) {
            return 1;
        }

        $team->delete();

        $this->stdErr->writeln('');
        $this->stdErr->writeln(sprintf('The team %s was deleted.', $this->getTeamLabel($team)));

        return 0;
    }
}
