<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Organization;

use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Api;
use Symfony\Contracts\Service\Attribute\Required;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Client\Model\Organization\Member;
use Platformsh\Client\Model\Organization\Organization;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;

class OrganizationCommandBase extends CommandBase
{
    private QuestionHelper $questionHelper;
    private Config $config;
    private Api $api;

    #[Required]
    public function autowire(Api $api, Config $config, QuestionHelper $questionHelper): void
    {
        $this->api = $api;
        $this->config = $config;
        $this->questionHelper = $questionHelper;
    }

    public function isEnabled(): bool
    {
        if (!$this->config->getBool('api.organizations')) {
            return false;
        }
        return parent::isEnabled();
    }

    protected function memberLabel(Member $member): string
    {
        if ($info = $member->getUserInfo()) {
            return $info->email;
        }

        return $member->id;
    }

    /**
     * Returns an example of another organization command, on this organization, for use in help messages.
     *
     * The current input needs to have been validated already (e.g. the --org option).
     *
     * Arguments will not be escaped (pre-escape them, or ideally only use args that do not use escaping).
     *
     * @param InputInterface $input
     * @param string $commandName
     * @param string $otherArgs
     *
     * @return string
     */
    protected function otherCommandExample(InputInterface $input, string $commandName, string $otherArgs = ''): string
    {
        $args = [
            $this->config->getStr('application.executable'),
            $commandName,
        ];
        if ($input->hasOption('org') && $input->getOption('org')) {
            $args[] = '--org ' . $input->getOption('org');
        }
        if ($otherArgs !== '') {
            $args[] = $otherArgs;
        }
        return \implode(' ', $args);
    }

    /**
     * Presents an interactive choice to pick a member in the organization.
     *
     * @param Organization $organization
     * @return Member
     */
    protected function chooseMember(Organization $organization): Member
    {
        $httpClient = $this->api->getHttpClient();
        $options = ['query' => ['page[size]' => 100]];
        $url = $organization->getUri() . '/members';
        /** @var Member[] $members */
        $members = [];
        $progress = new ProgressMessage($this->stdErr);
        $progress->showIfOutputDecorated('Loading users...');
        try {
            do {
                $result = Member::getCollectionWithParent($url, $httpClient, $options);
                $members = array_merge($members, $result['items']);
                $url = $result['collection']->getNextPageUrl();
            } while (!empty($url));
        } finally {
            $progress->done();
        }
        $byId = [];
        $choices = [];
        $emailAddresses = [];
        foreach ($members as $member) {
            if (!$member->getUserInfo()) {
                continue;
            }
            $emailAddresses[$member->user_id] = $member->getUserInfo()->email;
            $choices[$member->user_id] = $this->api->getMemberLabel($member);
            $byId[$member->user_id] = $member;
        }
        if (count($choices) < 25) {
            $default = null;
            if (isset($choices[$organization->owner_id])) {
                $choices[$organization->owner_id] .= ' (<info>owner - default</info>)';
                $default = $organization->owner_id;
            }
            $userId = $this->questionHelper->choose($choices, 'Enter a number to choose a user:', $default);
        } else {
            $userId = $this->questionHelper->askInput('Enter an email address to choose a user', null, array_values($emailAddresses), function (string $email) use ($emailAddresses): string {
                if (($key = array_search($email, $emailAddresses)) === false) {
                    throw new InvalidArgumentException('User not found: ' . $email);
                }
                return $key;
            });
        }
        $this->stdErr->writeln('');
        return $byId[$userId];
    }
}
