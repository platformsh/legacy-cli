<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Client\Model\Project;
use Platformsh\Client\Model\ProjectAccess;
use Platformsh\Client\Model\Ref\UserRef;
use Platformsh\Client\Model\UserAccess\ProjectUserAccess;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AccessApi
{
    /** @var array<string, ProjectUserAccess|ProjectAccess|null> */
    private static ?array $userCache = [];

    private OutputInterface $stdErr;

    public function __construct(private readonly Api $api, private readonly Config $config, OutputInterface $output)
    {
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    public function centralizedPermissionsEnabled(): bool
    {
        return $this->config->getBool('api.centralized_permissions')
            && $this->config->getBool('api.organizations');
    }

    /**
     * Loads a legacy project user ("project access" record) by ID.
     */
    private function doLoadLegacyProjectAccessById(Project $project, string $id): ?ProjectAccess
    {
        return ProjectAccess::get($id, $project->getUri() . '/access', $this->api->getHttpClient()) ?: null;
    }

    /**
     * Loads a project user by email address.
     *
     * Uses 2 different APIs to support backwards compatibility.
     *
     * @param Project $project
     * @param string $identifier
     * @param bool $reset
     *
     * @return ProjectUserAccess|ProjectAccess|null
     *  Null if the user does not exist, a ProjectUserAccess object if
     *  "Centralized Permissions" are enabled, or a ProjectAccess object
     *  otherwise.
     */
    public function loadProjectUser(Project $project, string $identifier, bool $reset = false): ProjectUserAccess|ProjectAccess|null
    {
        $byEmail = str_contains($identifier, '@');
        $cacheKey = $project->id . ':' . $identifier;
        if ($reset || !isset(self::$userCache[$cacheKey])) {
            if ($this->centralizedPermissionsEnabled()) {
                self::$userCache[$cacheKey] = $byEmail
                    ? $this->doLoadProjectUserByEmail($project, $identifier)
                    : $this->doLoadProjectUserById($project, $identifier);
            } else {
                self::$userCache[$cacheKey] = $byEmail
                    ? $this->doLoadLegacyProjectAccessByEmail($project, $identifier)
                    : $this->doLoadLegacyProjectAccessById($project, $identifier);
            }
        }
        return self::$userCache[$cacheKey];
    }

    /**
     * Loads a legacy project user ("project access" record) by email address.
     *
     * @param Project $project
     * @param string  $email
     *
     * @return ProjectAccess|null
     */
    private function doLoadLegacyProjectAccessByEmail(Project $project, string $email): ProjectAccess|null
    {
        foreach ($project->getUsers() as $user) {
            $info = $this->legacyUserInfo($user);
            if ($info['email'] === $email || strtolower($info['email']) === strtolower($email)) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Extracts a user's account info embedded in the legacy access API.
     *
     * @param ProjectAccess $access
     *
     * @return array{id: string, email: string, display_name: string, created_at: string, updated_at: ?string}
     */
    public function legacyUserInfo(ProjectAccess $access): array
    {
        $data = $access->getData();
        if (isset($data['_embedded']['users'])) {
            foreach ($data['_embedded']['users'] as $userData) {
                if ($userData['id'] === $access->id) {
                    return $userData;
                }
            }
        }
        throw new \RuntimeException('Failed to find user information for project access item: ' . $access->id);
    }

    /**
     * Loads a project user by ID.
     *
     * @param string $id
     * @param Project $project
     * @return ProjectUserAccess|null
     */
    private function doLoadProjectUserById(Project $project, string $id): ?ProjectUserAccess
    {
        $client = $this->api->getHttpClient();
        $endpointUrl = $project->getUri() . '/user-access';
        return ProjectUserAccess::get($id, $endpointUrl, $client) ?: null;
    }

    /**
     * Loads a project user by email, by paging through all the users on the project.
     *
     * @TODO replace this with a more efficient API when available
     *
     * @param string $email
     * @param Project $project
     * @return ProjectUserAccess|null
     */
    private function doLoadProjectUserByEmail(Project $project, string $email): ?ProjectUserAccess
    {
        $client = $this->api->getHttpClient();

        $progress = new ProgressMessage($this->stdErr);
        $progress->showIfOutputDecorated('Loading user information...');
        $endpointUrl = $project->getUri() . '/user-access';
        $collection = ProjectUserAccess::getCollectionWithParent($endpointUrl, $client, [
            'query' => ['page[size]' => 200],
        ])['collection'];
        $userRef = null;
        while (true) {
            $data = $collection->getData();
            if (!empty($data['ref:users'])) {
                foreach ($data['ref:users'] as $candidate) {
                    /** @var UserRef $candidate */
                    if ($candidate->email === $email || strtolower($candidate->email) === strtolower($email)) {
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
                        return new ProjectUserAccess($itemData, $endpointUrl, $client);
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
     * Lists project users.
     *
     * Uses 2 different APIs to support backwards compatibility.
     *
     * @param Project $project
     *
     * @return array<string, string> An array of user labels keyed by user ID.
     */
    public function listUsers(Project $project): array
    {
        $choices = [];
        if ($this->centralizedPermissionsEnabled()) {
            $items = ProjectUserAccess::getCollection($project->getUri() . '/user-access', 0, ['query' => ['page[size]' => 200]], $this->api->getHttpClient());
            foreach ($items as $item) {
                $choices[$item->user_id] = $this->getUserLabel($item);
            }
        } else {
            foreach ($project->getUsers() as $access) {
                $choices[$access->id] = $this->getUserLabel($access);
            }
        }
        natcasesort($choices);
        return $choices;
    }

    /**
     * Returns a label describing a user.
     */
    public function getUserLabel(ProjectUserAccess|ProjectAccess $access, bool $formatting = false): string
    {
        $format = $formatting ? '<info>%s</info> (%s)' : '%s (%s)';
        if ($access instanceof ProjectAccess) {
            $info = $this->legacyUserInfo($access);

            return sprintf($format, $info['display_name'], $info['email']);
        }
        $info = $access->getUserInfo();
        return sprintf($format, trim($info->first_name . ' ' . $info->last_name), $info->email);
    }
}
