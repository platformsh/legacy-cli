<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Team\Project;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\Team\TeamCommandBase;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Client\Exception\ApiResponseException;
use Platformsh\Client\Model\Organization\Project as OrgProject;
use Platformsh\Client\Model\Team\Team;
use Platformsh\Client\Model\Team\TeamProjectAccess;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

#[AsCommand(name: 'team:project:add', description: 'Add project(s) to a team')]
class TeamProjectAddCommand extends TeamCommandBase
{
    public function __construct(private readonly Api $api, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('projects', InputArgument::IS_ARRAY, "The project ID(s).\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('all', null, InputOption::VALUE_NONE, 'Add all the projects that currently exist in the organization');
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
        $teamProjectIds = array_map(fn(TeamProjectAccess $a) => $a->project_id, $teamProjects);

        $projectIds = ArrayArgument::getArgument($input, 'projects');

        if ($input->getOption('all')) {
            if ($projectIds) {
                $this->stdErr->writeln('The <error>--all</error> option cannot be used when project(s) are specified.');
                return 1;
            }
            $orgProjects = $this->loadOrgProjects($team);
            if (!$orgProjects) {
                $this->stdErr->writeln(sprintf("No projects were found in the team's organization (%s)", $team->organization_id));
                return 1;
            }
            foreach ($orgProjects as $orgProject) {
                // Pre-cache the label for this project.
                $this->api->getProjectLabel($orgProject);
                $projectIds[] = $orgProject->id;
            }
        } elseif (!$projectIds && $input->isInteractive()) {
            $orgProjects = $this->loadOrgProjects($team);
            if (!$orgProjects) {
                $this->stdErr->writeln(sprintf("No projects were found in the team's organization (%s)", $team->organization_id));
                return 1;
            }
            $orgProjectsFiltered = array_filter($orgProjects, fn(OrgProject $orgProject): bool => !in_array($orgProject->id, $teamProjectIds));
            if (!$orgProjectsFiltered) {
                $this->stdErr->writeln('The team currently has access to the project(s): ');
                foreach ($teamProjects as $teamProject) {
                    $this->stdErr->writeln(sprintf(' • %s', $this->api->getProjectLabel($teamProject)));
                }
                $this->stdErr->writeln('No new projects were found to add.');
                return 1;
            }
            $asChoice = count($orgProjectsFiltered) < 25 && count($orgProjectsFiltered) <= (new Terminal())->getHeight() - 3;

            if ($asChoice) {
                $options = [];
                foreach ($orgProjectsFiltered as $orgProject) {
                    $options[$orgProject->id] = $this->api->getProjectLabel($orgProject, false);
                }
                natcasesort($options);
                $choiceQuestionText = 'Enter a number to select a project to add to the team:';
                $default = null;
                do {
                    $choice = $this->questionHelper->choose($options, $choiceQuestionText, $default, false);
                    unset($options[$choice]);
                    if ($choice === 'finish') {
                        break;
                    }
                    $projectIds[] = $choice;
                    $options['finish'] = '<fg=cyan>[Finish selection]</>';
                    $default = 'finish';
                    if (count($options) === 1) {
                        break;
                    }
                    $this->stdErr->writeln('Selected project(s):');
                    $this->displayProjectsAsList($projectIds, $this->stdErr);
                    $this->stdErr->writeln('');
                    $choiceQuestionText = 'Enter a number to add another project to the selection (or Enter to finish):';
                } while (count($options));
            } else {
                $autocomplete = [];
                foreach ($orgProjectsFiltered as $orgProject) {
                    if ($orgProject->title) {
                        $autocomplete[$orgProject->id] = $orgProject->id . ' - <question>' . $orgProject->title . '</question>';
                    } else {
                        $autocomplete[$orgProject->id] = $orgProject->id;
                    }
                }
                asort($autocomplete, SORT_NATURAL | SORT_FLAG_CASE);
                $questionText = 'Enter an ID to select a project to add to the team';
                $first = true;
                do {
                    $choice = $this->questionHelper->askInput($questionText, null, array_values($autocomplete), function ($value) use ($autocomplete, $first, $teamProjectIds): ?string {
                        [$id, ] = explode(' - ', $value);
                        if (empty(trim($id))) {
                            if (!$first) {
                                return null;
                            }
                            throw new InvalidArgumentException('A project ID is required');
                        }
                        if (!isset($autocomplete[$id])) {
                            if (in_array($id, $teamProjectIds)) {
                                throw new InvalidArgumentException('The team already has access to the project: ' . $id);
                            }
                            throw new InvalidArgumentException('Project not found: ' . $id);
                        }
                        return $id;
                    });
                    $this->stdErr->writeln('');
                    if ($choice === null) {
                        break;
                    }
                    $projectIds[] = $choice;
                    unset($autocomplete[$choice]);
                    $first = false;
                    $this->stdErr->writeln('Selected project(s):');
                    $this->displayProjectsAsList($projectIds, $this->stdErr);
                    $this->stdErr->writeln('');
                    $questionText = 'Enter an ID to select an additional project (or Enter to finish)';
                } while (count($autocomplete));
            }
        } elseif (!$projectIds) {
            $this->stdErr->writeln('At least one project must be specified (in non-interactive mode).');
            return 1;
        }

        foreach ($projectIds as $key => $projectId) {
            if (in_array($projectId, $teamProjectIds)) {
                $this->stdErr->writeln(sprintf('The team already has access to the project %s', $this->api->getProjectLabel($projectId, 'comment')));
                unset($projectIds[$key]);
            }
        }

        if (!$projectIds) {
            $this->stdErr->writeln('There are no projects to add.');
            return 0;
        }

        if (count($projectIds) === 1) {
            $projectId = reset($projectIds);
            if (!$this->questionHelper->confirm(sprintf('Are you sure you want to add the project %s to the team %s?', $this->api->getProjectLabel($projectId), $this->getTeamLabel($team)))) {
                return 1;
            }
        } else {
            $this->stdErr->writeln('Selected projects:');
            $this->displayProjectsAsList($projectIds, $this->stdErr);
            $this->stdErr->writeln('');
            if (!$this->questionHelper->confirm(sprintf('Are you sure you want to add these %d projects to the team %s?', count($projectIds), $this->getTeamLabel($team)))) {
                return 1;
            }
        }

        $payload = [];
        foreach ($projectIds as $projectId) {
            $payload[] = ['project_id' => $projectId];
        }

        try {
            $this->api->getHttpClient()->post($team->getUri() . '/project-access', ['json' => $payload]);
        } catch (BadResponseException $e) {
            throw ApiResponseException::create($e->getRequest(), $e->getResponse(), $e);
        }

        $this->stdErr->writeln('');
        $this->stdErr->writeln(sprintf('The project(s) were successfully added to the team %s.', $this->getTeamLabel($team)));

        return 0;
    }

    /**
     * Displays a list of projects.
     *
     * @param string[] $projectIds
     */
    private function displayProjectsAsList(array $projectIds, OutputInterface $output): void
    {
        $selections = [];
        foreach ($projectIds as $projectId) {
            $selections[] = ' • ' . $this->api->getProjectLabel($projectId);
        }
        natcasesort($selections);
        $output->writeln($selections);
    }

    /**
     * Loads the projects in a team's organization.
     *
     * @param Team $team
     * @return OrgProject[]
     */
    private function loadOrgProjects(Team $team): array
    {
        $httpClient = $this->api->getHttpClient();
        $url = '/organizations/' . rawurlencode($team->organization_id) . '/projects';
        /** @var OrgProject[] $projects */
        $projects = [];
        $pageNumber = 1;
        $options = [];
        $options['query']['filter[status][in]'] = 'active,suspended';
        $progress = new ProgressMessage($this->stdErr);
        while ($url !== null) {
            if ($pageNumber > 1) {
                $progress->showIfOutputDecorated(sprintf('Loading projects (page %d)...', $pageNumber));
            }
            $result = OrgProject::getCollectionWithParent($url, $httpClient, $options);
            $progress->done();
            $projects = array_merge($projects, $result['items']);
            $url = $result['collection']->getNextPageUrl();
            $pageNumber++;
        }
        return $projects;
    }
}
