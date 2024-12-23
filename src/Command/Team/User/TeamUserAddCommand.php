<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Team\User;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\Team\TeamCommandBase;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Client\Exception\ApiResponseException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'team:user:add', description: 'Add a user to a team')]
class TeamUserAddCommand extends TeamCommandBase
{
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
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
        if (!$identifier) {
            if (!$input->isInteractive()) {
                $this->stdErr->writeln('A user must be specified (in non-interactive mode).');
                return 1;
            }
            $emails = [];
            foreach ($this->api->listMembers($organization) as $member) {
                if ($info = $member->getUserInfo()) {
                    $emails[] = $info->email;
                }
            }
            $identifier = $this->questionHelper->askInput('Enter an email address to add a user', null, $emails, function (string $value): string {
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Invalid email address:' . $value);
                }
                return $value;
            });
            $this->stdErr->writeln('');
        }

        if (str_contains((string) $identifier, '@')) {
            $orgMember = $this->api->loadMemberByEmail($organization, $identifier);
            if (!$orgMember) {
                $this->stdErr->writeln(sprintf('The user with email address <error>%s</error> was not found in the organization %s.', $identifier, $this->api->getOrganizationLabel($organization, 'comment')));
                $this->stdErr->writeln('');
                $this->stdErr->writeln('A team may only contain users who are part of the organization.');
                if ($this->getApplication()->has('organization:user:add')) {
                    $this->stdErr->writeln('');
                    $this->stdErr->writeln(sprintf(
                        "To invite the user, run:\n  <comment>%s org:user:add -o %s %s</comment>",
                        $this->config->getStr('application.executable'),
                        OsUtil::escapeShellArg($organization->id),
                        OsUtil::escapeShellArg($identifier),
                    ));
                }
                return 1;
            }
        } else {
            $orgMember = $organization->getMember($identifier);
            if (!$orgMember) {
                $this->stdErr->writeln(sprintf('The user <error>%s</error> was not found in the organization %s.', $identifier, $this->api->getOrganizationLabel($organization, 'comment')));
                return 1;
            }
        }

        if ($team->getMember($orgMember->user_id)) {
            $this->stdErr->writeln(sprintf('The user <info>%s</info> is already in the team %s.', $this->api->getMemberLabel($orgMember), $this->getTeamLabel($team)));
            return 0;
        }

        if (!$this->questionHelper->confirm(sprintf('Are you sure you want to add the user <info>%s</info> to the team %s?', $this->api->getMemberLabel($orgMember), $this->getTeamLabel($team)))) {
            return 1;
        }

        $payload = ['user_id' => $orgMember->user_id];

        try {
            $this->api->getHttpClient()->post($team->getUri() . '/members', ['json' => $payload]);
        } catch (BadResponseException $e) {
            throw ApiResponseException::create($e->getRequest(), $e->getResponse(), $e);
        }

        $this->stdErr->writeln(sprintf('The user was successfully added to the team %s.', $this->getTeamLabel($team)));

        return 0;
    }
}
