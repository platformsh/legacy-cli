<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Team\User;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\Team\TeamCommandBase;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Client\Exception\ApiResponseException;
use Platformsh\Client\Model\Team\Team;
use Platformsh\Client\Model\Team\TeamMember;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'team:user:delete', description: 'Remove a user from a team')]
class TeamUserDeleteCommand extends TeamCommandBase
{
    public function __construct(private readonly Api $api, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->addArgument('user', InputArgument::OPTIONAL, 'The user email address or ID');
        $this->selector->addOrganizationOptions($this->getDefinition());
        $this->addTeamOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $team = $this->validateTeamInput($input);
        if (!$team) {
            return 1;
        }
        $organization = $this->api->getOrganizationById($team->organization_id);
        if (!$organization) {
            $this->stdErr->writeln(sprintf('Failed to load team organization: <error>%s</error>.', $team->organization_id));
            return 1;
        }

        $identifier = $input->getArgument('user');
        if ($identifier) {
            if (str_contains((string) $identifier, '@')) {
                $orgMember = $this->api->loadMemberByEmail($organization, $identifier);
                if (!$orgMember) {
                    $this->stdErr->writeln(sprintf('The user with email address <error>%s</error> was not found in the organization %s', $identifier, $this->api->getOrganizationLabel($organization, 'error')));
                    return 1;
                }
                $member = $team->getMember($orgMember->user_id);
                if (!$member) {
                    $this->stdErr->writeln(sprintf('The user with email address <error>%s</error> is not part of the team %s', $identifier, $this->getTeamLabel($team, 'error')));
                    return 1;
                }
            } else {
                $member = $team->getMember($identifier);
                if (!$member) {
                    $this->stdErr->writeln(sprintf('The user <error>%s</error> was not found in the team %s', $identifier, $this->getTeamLabel($team, 'error')));
                    return 1;
                }
            }
        } elseif ($input->isInteractive()) {
            $members = $this->loadMembers($team);
            if (!$members) {
                $this->stdErr->writeln('No team users were found.');
                return 1;
            }
            $choices = [];
            $byId = [];
            foreach ($members as $member) {
                $choices[$member->user_id] = $this->api->getMemberLabel($member);
                $byId[$member->user_id] = $member;
            }
            $id = $this->questionHelper->choose($choices, 'Enter a number to choose a user to remove:', null, false);
            $member = $byId[$id];
        } else {
            $this->stdErr->writeln('A user must be specified (in non-interactive mode).');
            return 1;
        }

        if (!$this->questionHelper->confirm(sprintf('Are you sure you want to remove the user <comment>%s</comment> from the team %s?', $this->api->getMemberLabel($member), $this->getTeamLabel($team, 'comment')))) {
            return 1;
        }

        try {
            $member->delete();
        } catch (BadResponseException $e) {
            throw ApiResponseException::create($e->getRequest(), $e->getResponse(), $e);
        }

        $this->stdErr->writeln('');
        $this->stdErr->writeln(sprintf('The user <info>%s</info> was successfully removed from the team %s.', $this->api->getMemberLabel($member), $this->getTeamLabel($team)));

        return 0;
    }

    /**
     * Loads all team members.
     *
     * @param Team $team
     * @return TeamMember[]
     */
    private function loadMembers(Team $team): array
    {
        $httpClient = $this->api->getHttpClient();
        /** @var TeamMember[] $members */
        $members = [];
        $url = $team->getUri() . '/members';
        $progress = new ProgressMessage($this->stdErr);
        $pageNumber = 1;
        while ($url !== null) {
            if ($pageNumber > 1) {
                $progress->showIfOutputDecorated(sprintf('Loading team users (page %d)...', $pageNumber));
            }
            $result = TeamMember::getCollectionWithParent($url, $httpClient);
            $progress->done();
            $members = \array_merge($members, $result['items']);
            $url = $result['collection']->getNextPageUrl();
            $pageNumber++;
        }
        return $members;
    }
}
