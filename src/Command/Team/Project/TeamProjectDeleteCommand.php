<?php
namespace Platformsh\Cli\Command\Team\Project;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\Team\TeamCommandBase;
use Platformsh\Client\Exception\ApiResponseException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TeamProjectDeleteCommand extends TeamCommandBase
{
    protected function configure()
    {
        $this->setName('team:project:delete')
            ->setDescription('Remove a project from a team')
            ->addArgument('project', InputArgument::OPTIONAL, 'The project ID')
            ->addOrganizationOptions()
            ->addTeamOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $team = $this->validateTeamInput($input);
        if (!$team) {
            return 1;
        }
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        $teamProjects = $this->loadTeamProjects($team);

        $projectLabels = [];
        foreach ($teamProjects as $teamProject) {
            $projectLabels[$teamProject->project_id] = $this->api()->getProjectLabel($teamProject, false);
        }

        $projectId = $input->getArgument('project');
        if (!$projectId && $input->isInteractive()) {
            $options = $projectLabels;
            if (!$options) {
                $this->stdErr->writeln(sprintf('No projects were found on the team %s', $this->getTeamLabel($team, 'error')));
                return 1;
            }
            $questionText = 'Enter a number to choose a project to remove from the team:';
            $projectId = $questionHelper->choose($options, $questionText, null, false);
        } elseif (!$projectId) {
            $this->stdErr->writeln('A project ID must be specified (in non-interactive mode).');
            return 1;
        }

        if (!isset($projectLabels[$projectId])) {
            $this->stdErr->writeln(sprintf('The project ID <error>%s</error> was not found in the team %s.', $projectId, $this->getTeamLabel($team, 'error')));
            return 1;
        }

        if (!$questionHelper->confirm(sprintf('Are you sure you want to remove the project <comment>%s</comment> from the team %s?', $projectLabels[$projectId], $this->getTeamLabel($team, 'comment')))) {
            return 1;
        }

        try {
            $this->api()->getHttpClient()->delete($team->getUri() . '/project-access/' . rawurlencode($projectId));
        } catch (BadResponseException $e) {
            throw ApiResponseException::create($e->getRequest(), $e->getResponse(), $e);
        }

        $this->stdErr->writeln('');
        $this->stdErr->writeln(sprintf('The project <info>%s</info> was successfully removed from the team %s.', $projectLabels[$projectId], $this->getTeamLabel($team)));

        return 0;
    }
}
