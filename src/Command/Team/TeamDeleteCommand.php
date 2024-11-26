<?php
namespace Platformsh\Cli\Command\Team;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TeamDeleteCommand extends TeamCommandBase
{

    protected function configure()
    {
        $this->setName('team:delete')
            ->setDescription('Delete a team')
            ->addOrganizationOptions()
            ->addTeamOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $team = $this->validateTeamInput($input);
        if (!$team) {
            return 1;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        if (!$questionHelper->confirm(\sprintf('Are you sure you want to delete the team %s?', $this->getTeamLabel($team, 'comment')), false)) {
            return 1;
        }

        $team->delete();

        $this->stdErr->writeln('');
        $this->stdErr->writeln(sprintf('The team %s was deleted.', $this->getTeamLabel($team)));

        return 0;
    }
}
