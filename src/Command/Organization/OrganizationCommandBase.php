<?php

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
    private readonly QuestionHelper $questionHelper;
    private readonly Config $config;
    private readonly Api $api;
    #[Required]
    public function autowire(Api $api, Config $config, QuestionHelper $questionHelper) : void
    {
        $this->api = $api;
        $this->config = $config;
        $this->questionHelper = $questionHelper;
    }
    public function isEnabled(): bool
    {
        if (!$this->config->getWithDefault('api.organizations', false)) {
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
    protected function otherCommandExample(InputInterface $input, $commandName, $otherArgs = ''): string
    {
        $args = [
            $this->config->get('application.executable'),
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
            if ($lower === \strtolower((string) $name) || $lower === \strtolower($code)) {
                return $code;
            }
        }
        return $country;
    }

    /**
     * Presents an interactive choice to pick a member in the organization.
     *
     * @param Organization $organization
     * @return Member
     */
    protected function chooseMember(Organization $organization)
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
        $questionHelper = $this->questionHelper;
        if (count($choices) < 25) {
            $default = null;
            if (isset($choices[$organization->owner_id])) {
                $choices[$organization->owner_id] .= ' (<info>owner - default</info>)';
                $default = $organization->owner_id;
            }
            $userId = $questionHelper->choose($choices, 'Enter a number to choose a user:', $default);
        } else {
            $userId = $questionHelper->askInput('Enter an email address to choose a user', null, array_values($emailAddresses), function (string $email) use ($emailAddresses): int|string {
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
