<?php

namespace Platformsh\Cli\Command\Organization;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Client\Model\Organization\Member;
use Platformsh\Client\Model\Organization\Organization;
use Platformsh\Client\Model\Ref\UserRef;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;

class OrganizationCommandBase extends CommandBase
{
    public function isEnabled()
    {
        if (!$this->config()->getWithDefault('api.organizations', false)) {
            return false;
        }
        return parent::isEnabled();
    }

    protected function memberLabel(Member $member)
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
    protected function otherCommandExample(InputInterface $input, $commandName, $otherArgs = '')
    {
        $args = [
            $this->config()->get('application.executable'),
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
     * Returns a list of countries, keyed by 2-letter country code.
     *
     * @return array
     */
    protected function countryList()
    {
        static $data;
        if (isset($data)) {
            return $data;
        }
        $filename = CLI_ROOT . '/resources/cldr/countries.json';
        $data = \json_decode((string) \file_get_contents($filename), true);
        if (!$data) {
            throw new \RuntimeException('Failed to read CLDR file: ' . $filename);
        }
        return $data;
    }

    /**
     * Normalizes a given country, transforming it into a country code, if possible.
     *
     * @param string $country
     *
     * @return string
     */
    protected function normalizeCountryCode($country)
    {
        $countryList = $this->countryList();
        if (isset($countryList[$country])) {
            return $country;
        }
        // Exact match.
        if (($code = \array_search($country, $countryList)) !== false) {
            return $code;
        }
        // Case-insensitive match.
        $lower = \strtolower($country);
        foreach ($countryList as $code => $name) {
            if ($lower === \strtolower($name) || $lower === \strtolower($code)) {
                return $code;
            }
        }
        return $country;
    }

    /**
     * Loads an organization user by email, by paging through all the users in the organization.
     *
     * @TODO replace this with a more efficient API when available
     *
     * @param Organization $organization
     * @param string $email
     * @return Member|null
     */
    protected function loadMemberByEmail(Organization $organization, $email)
    {
        $client = $this->api()->getHttpClient();

        $progress = new ProgressMessage($this->stdErr);
        $progress->showIfOutputDecorated('Loading user information...');
        $endpointUrl = $organization->getUri() . '/members';
        $collection = Member::getCollectionWithParent($endpointUrl, $client, [
            'query' => ['page[size]' => 100],
        ])['collection'];
        $userRef = null;
        while (true) {
            $data = $collection->getData();
            if (!empty($data['ref:users'])) {
                foreach ($data['ref:users'] as $candidate) {
                    /** @var ?UserRef $candidate */
                    if ($candidate && ($candidate->email === $email || strtolower($candidate->email) === strtolower($email))) {
                        $userRef = $candidate;
                        break;
                    }
                }
            }
            if (isset($userRef)) {
                foreach ($data['items'] as $itemData) {
                    if (isset($itemData['user_id']) && $itemData['user_id'] === $userRef->id) {
                        $itemData['ref:users'][$userRef->id] = $userRef;
                        $progress->done();
                        return new Member($itemData, $endpointUrl, $client);
                    }
                }
            }
            if (!$collection->hasNextPage()) {
                break;
            }
            $collection = $collection->fetchNextPage();
        }
        $progress->done();
        return null;
    }

    /**
     * Presents an interactive choice to pick a member in the organization.
     *
     * @param Organization $organization
     * @return Member
     */
    protected function chooseMember(Organization $organization)
    {
        $httpClient = $this->api()->getHttpClient();
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
            $choices[$member->user_id] = $this->getMemberLabel($member);
            $byId[$member->user_id] = $member;
        }
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if (count($choices) < 25) {
            $default = null;
            if (isset($choices[$organization->owner_id])) {
                $choices[$organization->owner_id] .= ' (<info>owner - default</info>)';
                $default = $organization->owner_id;
            }
            $userId = $questionHelper->choose($choices, 'Enter a number to choose a user:', $default);
        } else {
            $userId = $questionHelper->askInput('Enter an email address to choose a user', null, array_values($emailAddresses), function ($email) use ($emailAddresses) {
                if (($key = array_search($email, $emailAddresses)) === false) {
                    throw new InvalidArgumentException('User not found: ' . $email);
                }
                return $key;
            });
        }
        return $byId[$userId];
    }

    protected function getMemberLabel(Member $member)
    {
        if ($userInfo = $member->getUserInfo()) {
            $label = sprintf('%s (%s)', trim($userInfo->first_name . ' ' . $userInfo->last_name), $userInfo->email);
        } else {
            $label = $member->user_id;
        }
        return $label;
    }
}
