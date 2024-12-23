<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Team;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Api;
use Symfony\Contracts\Service\Attribute\Required;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Cli\Exception\NoOrganizationsException;
use Platformsh\Client\Model\Organization\Organization;
use Platformsh\Client\Model\Team\Team;
use Platformsh\Client\Model\Team\TeamProjectAccess;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

class TeamCommandBase extends CommandBase
{
    private Selector $selector;
    private QuestionHelper $questionHelper;
    private Config $config;
    private Api $api;

    #[Required]
    public function autowire(Api $api, Config $config, QuestionHelper $questionHelper, Selector $selector): void
    {
        $this->api = $api;
        $this->config = $config;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
    }

    public function isEnabled(): bool
    {
        return $this->config->getBool('api.teams')
            && $this->config->getBool('api.centralized_permissions')
            && $this->config->getBool('api.organizations')
            && parent::isEnabled();
    }

    /**
     * Adds the --team (-t) option.
     *
     * @return self
     */
    protected function addTeamOption(): self
    {
        $this->addOption('team', 't', InputOption::VALUE_REQUIRED, 'The team ID');
        return $this;
    }

    /**
     * Validates the --team option or asks for a team interactively.
     *
     * @param InputInterface $input
     * @param Organization|null $organization
     * @return Team|false
     */
    public function validateTeamInput(InputInterface $input, ?Organization $organization = null): false|Team
    {
        if ($organization === null && $input->hasOption('org') && $input->getOption('org') !== null) {
            $organization = $this->selectOrganization($input);
            if (!$organization) {
                return false;
            }
        }
        if ($teamInput = $input->getOption('team')) {
            $team = $this->api->getClient()->getTeam($teamInput);
            if (!$team) {
                $this->stdErr->writeln('Team not found: <error>' . $teamInput . '</error>');
                return false;
            }
            if ($organization && $team->organization_id !== $organization->id) {
                $this->stdErr->writeln(sprintf('The team %s is not part of the selected organization, %s.', $this->getTeamLabel($team, 'error'), $this->api->getOrganizationLabel($organization, 'error')));
                return false;
            }
            if (!$organization && !$this->api->getOrganizationById($team->organization_id)) {
                $this->stdErr->writeln(sprintf('Failed to load team organization: <error>%s</error>.', $team->organization_id));
                return false;
            }
            if ($this->stdErr->isVerbose()) {
                $this->stdErr->writeln(sprintf('Selected team: %s', $this->getTeamLabel($team)));
                $this->stdErr->writeln('');
            }
            return $team;
        }

        if (!$input->isInteractive()) {
            throw new InvalidArgumentException('A --team is required (in non-interactive mode)');
        }

        $organization = $organization ?: $this->selectOrganization($input);
        if (!$organization) {
            return false;
        }

        $teams = $this->loadTeams($organization);
        if (count($teams) === 0) {
            $this->stdErr->writeln(sprintf('No teams were found in the organization %s', $this->api->getOrganizationLabel($organization, 'error')));
            return false;
        }

        if (count($teams) === 1) {
            $team = reset($teams);
            $this->stdErr->writeln(sprintf('Selected team: %s (by default)', $this->getTeamLabel($team)));
            $this->stdErr->writeln('');
            return $team;
        }

        return $this->chooseTeam($teams);
    }

    /**
     * Selects an organization that has teams support.
     * @noinspection PhpUnused
     */
    protected function selectOrganization(InputInterface $input): Organization|false
    {
        try {
            $organization = $this->selector->selectOrganization($input, 'members', 'teams');
        } catch (NoOrganizationsException $e) {
            if ($e->getTotalNumOrgs() === 0) {
                $this->stdErr->writeln('No organizations found.');
                if ($this->getApplication()->has('organization:create')) {
                    $this->stdErr->writeln('');
                    $this->stdErr->writeln(sprintf('To create an organization, run: <comment>%s org:create</comment>', $this->config->getStr('application.executable')));
                }
                return false;
            }
            $this->stdErr->writeln('No organizations were found in which you can manage teams.');
            if ($this->getApplication()->has('organization:list')) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(sprintf('To list organizations, run: <comment>%s organizations</comment>', $this->config->getStr('application.executable')));
            }
            return false;
        }
        if (!in_array('teams', $organization->capabilities)) {
            $this->stdErr->writeln(sprintf('The organization %s does not have teams support.', $this->api->getOrganizationLabel($organization, 'comment')));
            return false;
        }
        if (!$organization->hasLink('members')) {
            $this->stdErr->writeln(sprintf('You do not have permission to manage teams in the organization %s.', $this->api->getOrganizationLabel($organization, 'comment')));
            return false;
        }
        return $organization;
    }

    /**
     * Loads teams in an organization.
     *
     * @param Organization $organization The organization.
     * @param bool $fetchAllPages If false, only one page will be fetched.
     * @param array<string, string> $params Extra query parameters.
     *
     * @return Team[]
     */
    protected function loadTeams(Organization $organization, bool $fetchAllPages = true, array $params = []): array
    {
        $httpClient = $this->api->getHttpClient();
        $options = ['query' => array_merge(['filter[organization_id]' => $organization->id, 'sort' => 'label'], $params)];
        $url = '/teams';
        /** @var Team[] $teams */
        $teams = [];
        $progress = new ProgressMessage($this->stdErr);
        $pageNumber = 1;
        do {
            if ($pageNumber > 1) {
                $progress->showIfOutputDecorated(sprintf('Loading teams (page %d)...', $pageNumber));
            }
            $result = Team::getCollectionWithParent($url, $httpClient, $options);
            $progress->done();
            $teams = array_merge($teams, $result['items']);
            $url = $result['collection']->getNextPageUrl();
            $pageNumber++;
        } while ($url && $fetchAllPages);
        return $teams;
    }

    /**
     * Presents an interactive choice to pick a team in the organization.
     *
     * @param Team[] $teams
     * @return Team
     */
    protected function chooseTeam(array $teams): Team
    {
        $choices = [];
        $byId = [];
        foreach ($teams as $team) {
            $choices[$team->id] = $team->label . ' (' . $team->id . ')';
            $byId[$team->id] = $team;
        }
        $teamId = $this->questionHelper->choose($choices, 'Enter a number to choose a team:');
        return $byId[$teamId];
    }

    /**
     * Loads all the projects a team can access.
     *
     * @param Team $team
     *
     * @return TeamProjectAccess[]
     */
    protected function loadTeamProjects(Team $team): array
    {
        $httpClient = $this->api->getHttpClient();
        /** @var TeamProjectAccess[] $projects */
        $projects = [];
        $options = ['query' => ['sort' => 'project_title']];
        $url = $team->getUri() . '/project-access';
        $progress = new ProgressMessage($this->stdErr);
        $pageNumber = 1;
        while ($url !== null) {
            if ($pageNumber > 1) {
                $progress->showIfOutputDecorated(sprintf('Loading team projects (page %d)...', $pageNumber));
            }
            $result = TeamProjectAccess::getCollectionWithParent($url, $httpClient, $options);
            $progress->done();
            $projects = \array_merge($projects, $result['items']);
            $url = $result['collection']->getNextPageUrl();
            $pageNumber++;
        }
        return $projects;
    }

    /**
     * Returns a team label.
     *
     * @param Team $team
     * @param string|false $tag
     *
     * @return string
     */
    protected function getTeamLabel(Team $team, string|false $tag = 'info'): string
    {
        $pattern = $tag !== false ? '<%1$s>%2$s</%1$s> (%3$s)' : '%2$s (%3$s)';

        return sprintf($pattern, $tag, $team->label, $team->id);
    }
}
