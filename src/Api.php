<?php

namespace Platformsh\Cli;

use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Cache\FilesystemCache;
use Doctrine\Common\Cache\VoidCache;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Pool;
use Platformsh\Cli\Event\EnvironmentsChangedEvent;
use Platformsh\Cli\Util\Util;
use Platformsh\Client\Connection\Connector;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Platformsh\Client\Model\ProjectAccess;
use Platformsh\Client\Model\Resource as ApiResource;
use Platformsh\Client\PlatformClient;
use Platformsh\Client\Session\Storage\File;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Decorates the PlatformClient API client to provide aggressive caching.
 */
class Api
{
    /** @var CliConfig */
    protected $config;

    /** @var null|EventDispatcherInterface */
    protected $dispatcher;

    /** @var string */
    protected static $sessionId = 'default';

    /** @var string|null */
    protected static $apiToken;

    /** @var string */
    protected static $apiTokenType = 'exchange';

    /** @var \Doctrine\Common\Cache\CacheProvider */
    protected static $cache;

    /** @var PlatformClient */
    protected static $client;

    /**
     * Constructor.
     *
     * @param CliConfig|null                $config
     * @param EventDispatcherInterface|null $dispatcher
     */
    public function __construct(CliConfig $config = null, EventDispatcherInterface $dispatcher = null)
    {
        $this->config = $config ?: new CliConfig();
        $this->dispatcher = $dispatcher;

        self::$sessionId = $this->config->get('api.session_id') ?: 'default';

        if (!isset(self::$apiToken)) {
            // Exchangeable API tokens.
            if ($this->config->has('api.token')) {
                self::$apiToken = $this->config->get('api.token');
                self::$apiTokenType = 'exchange';
            }
            // Permanent, personal access token (deprecated).
            elseif ($this->config->has('api.permanent_access_token')) {
                self::$apiToken = $this->config->get('api.permanent_access_token');
                self::$apiTokenType = 'access';
            }
        }

        $this->setUpCache();
    }

    /**
     * Returns whether the CLI is authenticating using an API token.
     *
     * @return bool
     */
    public function hasApiToken()
    {
        return isset(self::$apiToken);
    }

    /**
     * Get an HTTP User Agent string representing this application.
     *
     * @return string
     */
    protected function getUserAgent()
    {
        return sprintf(
            '%s/%s (%s; %s; PHP %s)',
            str_replace(' ', '-', $this->config->get('application.name')),
            $this->config->get('application.version'),
            php_uname('s'),
            php_uname('r'),
            PHP_VERSION
        );
    }

    /**
     * Get the API client object.
     *
     * @param bool $autoLogin Whether to log in, if the client is not already
     *                        authenticated (default: true).
     *
     * @return PlatformClient
     */
    public function getClient($autoLogin = true)
    {
        if (!isset(self::$client)) {
            $connectorOptions = [];
            $connectorOptions['accounts'] = $this->config->get('api.accounts_api_url');
            $connectorOptions['verify'] = !$this->config->get('api.skip_ssl');
            $connectorOptions['debug'] = $this->config->get('api.debug');
            $connectorOptions['client_id'] = $this->config->get('api.oauth2_client_id');
            $connectorOptions['user_agent'] = $this->getUserAgent();
            $connectorOptions['api_token'] = self::$apiToken;
            $connectorOptions['api_token_type'] = self::$apiTokenType;

            // Proxy support with the http_proxy or https_proxy environment
            // variables.
            $proxies = [];
            foreach (['https', 'http'] as $scheme) {
                $proxies[$scheme] = str_replace('http://', 'tcp://', getenv($scheme . '_proxy'));
            }
            $proxies = array_filter($proxies);
            if (count($proxies)) {
                $connectorOptions['proxy'] = count($proxies) == 1 ? reset($proxies) : $proxies;
            }

            $connector = new Connector($connectorOptions);

            // Set up a persistent session to store OAuth2 tokens. By default,
            // this will be stored in a JSON file:
            // $HOME/.platformsh/.session/sess-cli-default/sess-cli-default.json
            $session = $connector->getSession();
            $session->setId('cli-' . self::$sessionId);
            $session->setStorage(new File($this->config->getUserConfigDir() . '/.session'));

            self::$client = new PlatformClient($connector);

            if (isset($this->dispatcher) && $autoLogin && !$connector->isLoggedIn()) {
                $this->dispatcher->dispatch('login_required');
            }
        }

        return self::$client;
    }

    protected function setUpCache()
    {
        if (!isset(self::$cache)) {
            if (!empty($this->config->get('api.disable_cache'))) {
                self::$cache = new VoidCache();
            } else {
                self::$cache = new FilesystemCache(
                    $this->config->getUserConfigDir() . '/cache',
                    FilesystemCache::EXTENSION,
                    0077 // Remove all permissions from the group and others.
                );
            }
        }
    }

    /**
     * Return the user's projects.
     *
     * @param bool|null $refresh Whether to refresh the list of projects.
     *
     * @return Project[] The user's projects, keyed by project ID.
     */
    public function getProjects($refresh = null)
    {
        $cacheKey = sprintf('%s:projects', self::$sessionId);
        $cached = self::$cache->fetch($cacheKey);

        if ($refresh === false && !$cached) {
            return [];
        }

        /** @var Project[] $projects */
        $projects = [];

        $guzzleClient = $this->getClient()->getConnector()->getClient();

        if ($refresh || !$cached) {
            // Load the list of the user's projects. This originates from the
            // central Accounts API, and as such, contains a minimal amount of
            // data about each project.
            $requests = [];
            foreach ($this->getClient()->getProjects() as $project) {
                $requests[] = $guzzleClient->createRequest('get', $project->getUri());
            }

            // Load data from each project's API endpoint, concurrently, and
            // save it into $cachedProjects.
            $cached = [];
            Pool::send($guzzleClient, $requests, [
                'complete' => function (CompleteEvent $event) use (&$cached, $guzzleClient) {
                    $data = $event->getResponse()->json();
                    $cached[$data['id']] = $data;
                    $cached[$data['id']]['_endpoint'] = $event->getRequest()->getUrl();
                },
                'pool_size' => 8,
            ]);

            self::$cache->save($cacheKey, $cached, $this->config->get('api.projects_ttl'));
        }

        foreach ((array) $cached as $id => $data) {
            $projects[$id] = new Project($data, $data['_endpoint'], $guzzleClient, true);
        }

        return $projects;
    }

    /**
     * Return the user's project with the given id.
     *
     * @param string $id        The project ID, or a full URL to the project
     *                          (this can be any API or web interface URL for
     *                          the project).
     * @param string $host      The project's hostname, if $id is just an ID.
     *                          If not provided, the hostname will be determined
     *                          from the user's projects list.
     * @param bool|null $refresh   Whether to bypass the cache.
     *
     * @return Project|false
     */
    public function getProject($id, $host = null, $refresh = null)
    {
        // Find the project in the user's main project list. This uses a cache.
        $projects = $this->getProjects($refresh);
        if (isset($projects[$id])) {
            return $projects[$id];
        }
        // Get the project directly if a hostname is specified.
        if (!empty($host)) {
            $scheme = 'https';
            if (($pos = strpos($host, '//')) !== false) {
                $scheme = parse_url($host, PHP_URL_SCHEME);
                $host = substr($host, $pos + 2);
            }
            return $this->getClient()->getProjectDirect($id, $host, $scheme != 'http');
        }

        return false;
    }

    /**
     * Return the user's environments.
     *
     * @param Project   $project The project.
     * @param bool|null $refresh Whether to refresh the list.
     * @param bool      $events  Whether to update Drush aliases if the list changes.
     *
     * @return Environment[] The user's environments.
     */
    public function getEnvironments(Project $project, $refresh = null, $events = true)
    {
        $projectId = $project->getProperty('id');

        static $staticEnvironmentsCache;
        if (!$refresh && isset($staticEnvironmentsCache[$projectId])) {
            return $staticEnvironmentsCache[$projectId];
        }

        $cacheKey = 'environments:' . $projectId;
        $cached = self::$cache->fetch($cacheKey);

        if ($refresh === false && !$cached) {
            return [];
        }
        elseif ($refresh || !$cached) {
            $environments = [];
            $toCache = [];
            foreach ($project->getEnvironments() as $environment) {
                $environments[$environment->id] = $environment;
                $toCache[$environment->id] = $environment->getData();
            }

            // Dispatch an event if the list of environments has changed.
            if (isset($this->dispatcher) && $events && (!$cached || array_diff_key($environments, $cached))) {
                $this->dispatcher->dispatch(
                    'environments_changed',
                    new EnvironmentsChangedEvent($project, $environments)
                );
            }

            self::$cache->save($cacheKey, $toCache, $this->config->get('api.environments_ttl'));
        } else {
            $environments = [];
            $endpoint = $project->hasLink('self') ? $project->getLink('self', true) : $project->getProperty('endpoint');
            $guzzleClient = $this->getClient()->getConnector()->getClient();
            foreach ((array) $cached as $id => $data) {
                $environments[$id] = new Environment($data, $endpoint, $guzzleClient, true);
            }
        }

        $staticEnvironmentsCache[$projectId] = $environments;

        return $environments;
    }

    /**
     * Get a single environment.
     *
     * @param string  $id      The environment ID to load.
     * @param Project $project The project.
     * @param bool|null $refresh
     *
     * @return Environment|false The environment, or false if not found.
     */
    public function getEnvironment($id, Project $project, $refresh = null)
    {
        // Statically cache not found environments.
        static $notFound = [];
        $cacheKey = $project->id . ':' . $id;
        if (!$refresh && isset($notFound[$cacheKey])) {
            return false;
        }

        $environments = $this->getEnvironments($project, $refresh);
        if (!isset($environments[$id])) {
            $notFound[$cacheKey] = true;

            return false;
        }

        return $environments[$id];
    }

    /**
     * Get a user's account info.
     *
     * @param ProjectAccess $user
     * @param bool $reset
     *
     * @return array
     *   An array containing 'email' and 'display_name'.
     */
    public function getAccount(ProjectAccess $user, $reset = false)
    {
        $cacheKey = 'account:' . $user->id;
        if ($reset || !($details = self::$cache->fetch($cacheKey))) {
            $details = $user->getAccount()->getProperties();
            self::$cache->save($cacheKey, $details, $this->config->get('api.users_ttl'));
        }

        return $details;
    }

    /**
     * Clear the cache.
     */
    public function clearCache()
    {
        self::$cache->flushAll();
    }

    /**
     * Clear the environments cache for a project.
     *
     * Use this after creating/deleting/updating environment(s).
     *
     * @param string $projectId
     */
    public function clearEnvironmentsCache($projectId)
    {
        self::$cache->delete('environments:' . $projectId);
    }

    /**
     * Clear the projects cache.
     */
    public function clearProjectsCache()
    {
        self::$cache->delete(sprintf('%s:projects', self::$sessionId));
    }

    /**
     * @return CacheProvider
     */
    public function getCache()
    {
        return self::$cache;
    }

    /**
     * Sort resources.
     *
     * @param ApiResource[] &$resources
     * @param string        $propertyPath
     *
     * @return ApiResource[]
     */
    public static function sortResources(array &$resources, $propertyPath)
    {
        uasort($resources, function (ApiResource $a, ApiResource $b) use ($propertyPath) {
            $valueA = static::getNestedProperty($a, $propertyPath, false);
            $valueB = static::getNestedProperty($b, $propertyPath, false);

            switch (gettype($valueA)) {
                case 'string':
                    return strcasecmp($valueA, $valueB);

                case 'integer':
                case 'double':
                case 'boolean':
                    return $valueA - $valueB;
            }

            return 0;
        });

        return $resources;
    }

    /**
     * Get a nested property of a resource, via a dot-separated string path.
     *
     * @param ApiResource $resource
     * @param string      $propertyPath
     * @param bool        $lazyLoad
     *
     * @throws \InvalidArgumentException if the property is not found.
     *
     * @return mixed
     */
    public static function getNestedProperty(ApiResource $resource, $propertyPath, $lazyLoad = true)
    {
        if (!strpos($propertyPath, '.')) {
            return $resource->getProperty($propertyPath, true, $lazyLoad);
        }

        $parents = explode('.', $propertyPath);
        $propertyName = array_shift($parents);
        $property = $resource->getProperty($propertyName, true, $lazyLoad);
        if (!is_array($property)) {
            throw new \InvalidArgumentException(sprintf('Invalid path "%s": the property "%s" is not an array.', $propertyPath, $propertyName));
        }
        $value = Util::getNestedArrayValue($property, $parents, $keyExists);
        if (!$keyExists) {
            throw new \InvalidArgumentException('Property not found: ' . $propertyPath);
        }

        return $value;
    }
}
