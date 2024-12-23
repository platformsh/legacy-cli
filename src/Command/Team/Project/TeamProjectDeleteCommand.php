<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Team\Project;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\Team\TeamCommandBase;
use Platformsh\Client\Exception\ApiResponseException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'team:project:delete', description: 'Remove a project from a team')]
class TeamProjectDeleteCommand extends TeamCommandBase
{
    public function __construct(private readonly Api $api, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('project', InputArgument::OPTIONAL, 'The project ID');
        $this->selector->addOrganizationOptions($this->getDefinition());
        $this->addTeamOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $team = $this->validateTeamInput($input);
        if (!$team) {
            return 1;
        }

        $teamProjects = $this->loadTeamProjects($team);

        $projectLabels = [];
        foreach ($teamProjects as $teamProject) {
            $projectLabels[$teamProject->project_id] = $this->api->getProjectLabel($teamProject, false);
        }

        $projectId = $input->getArgument('project');
        if (!$projectId && $input->isInteractive()) {
            $options = $projectLabels;
            if (!$options) {
                $this->stdErr->writeln(sprintf('No projects were found on the team %s', $this->getTeamLabel($team, 'error')));
                return 1;
            }
            $questionText = 'Enter a number to choose a project to remove from the team:';
            $projectId = $this->questionHelper->choose($options, $questionText, null, false);
        } elseif (!$projectId) {
            $this->stdErr->writeln('A project ID must be specified (in non-interactive mode).');
            return 1;
        }

        if (!isset($projectLabels[$projectId])) {
            $this->stdErr->writeln(sprintf('The project ID <error>%s</error> was not found in the team %s.', $projectId, $this->getTeamLabel($team, 'error')));
            return 1;
        }

        if (!$this->questionHelper->confirm(sprintf('Are you sure you want to remove the project <comment>%s</comment> from the team %s?', $projectLabels[$projectId], $this->getTeamLabel($team, 'comment')))) {
            return 1;
        }

        try {
            $this->api->getHttpClient()->delete($team->getUri() . '/project-access/' . rawurlencode((string) $projectId));
        } catch (BadResponseException $e) {
            throw ApiResponseException::create($e->getRequest(), $e->getResponse(), $e);
        }

        $this->stdErr->writeln('');
        $this->stdErr->writeln(sprintf('The project <info>%s</info> was successfully removed from the team %s.', $projectLabels[$projectId], $this->getTeamLabel($team)));

        return 0;
    }
}
