<?php

namespace Platformsh\Cli;

use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Cache\FilesystemCache;
use Doctrine\Common\Cache\VoidCache;
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
        if (self::$sessionId === 'api-token') {
            throw new \InvalidArgumentException('Invalid session ID: ' . self::$sessionId);
        }

        if (!isset(self::$apiToken)) {
            // Exchangeable API tokens: a token which is exchanged for a
            // temporary access token.
            if ($this->config->has('api.token_file')) {
                self::$apiToken = $this->loadTokenFromFile($this->config->get('api.token_file'));
                self::$apiTokenType = 'exchange';
                self::$sessionId = 'api-token';
            }
            elseif ($this->config->has('api.token')) {
                self::$apiToken = $this->config->get('api.token');
                self::$apiTokenType = 'exchange';
                self::$sessionId = 'api-token';
            }
            // Permanent, personal access token (deprecated) - an OAuth 2.0
            // bearer token which is used directly in API requests.
            elseif ($this->config->has('api.access_token_file')) {
                self::$apiToken = $this->loadTokenFromFile($this->config->get('api.access_token_file'));
                self::$apiTokenType = 'access';
            }
            elseif ($this->config->has('api.access_token')) {
                self::$apiToken = $this->config->get('api.access_token');
                self::$apiTokenType = 'access';
            }
        }

        $this->setUpCache();
    }

    /**
     * Load an API token from a file.
     *
     * @param string $filename
     *   A filename, either relative to the user config directory, or absolute.
     *
     * @return string
     */
    protected function loadTokenFromFile($filename)
    {
        if (strpos($filename, '/') !== 0 && strpos($filename, '\\') !== 0) {
            $filename = $this->config->getUserConfigDir() . '/' . $filename;
        }

        $content = file_get_contents($filename);
        if ($content === false) {
            throw new \RuntimeException('Failed to read file: ' . $filename);
        }

        return trim($content);
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
            if (PHP_SAPI === 'cli') {
                $proxies = [];
                foreach (['https', 'http'] as $scheme) {
                    $proxies[$scheme] = str_replace('http://', 'tcp://', getenv($scheme . '_proxy'));
                }
                $proxies = array_filter($proxies);
                if (count($proxies)) {
                    $connectorOptions['proxy'] = count($proxies) == 1 ? reset($proxies) : $proxies;
                }
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

        /** @var Project[] $projects */
        $projects = [];

        $cached = self::$cache->fetch($cacheKey);

        if ($refresh === false && !$cached) {
            return [];
        } elseif ($refresh || !$cached) {
            foreach ($this->getClient()->getProjects() as $project) {
                $projects[$project->id] = $project;
            }

            $cachedProjects = [];
            foreach ($projects as $id => $project) {
                $cachedProjects[$id] = $project->getData();
                $cachedProjects[$id]['_endpoint'] = $project->getUri(true);
            }

            self::$cache->save($cacheKey, $cachedProjects, $this->config->get('api.projects_ttl'));
        } else {
            $guzzleClient = $this->getClient()->getConnector()->getClient();
            foreach ((array) $cached as $id => $data) {
                $projects[$id] = new Project($data, $data['_endpoint'], $guzzleClient);
            }
        }

        return $projects;
    }

    /**
     * Return the user's project with the given ID.
     *
     * @param string      $id      The project ID.
     * @param string|null $host    The project's hostname.
     * @param bool|null   $refresh Whether to bypass the cache.
     *
     * @return Project|false
     */
    public function getProject($id, $host = null, $refresh = null)
    {
        // Find the project in the user's main project list. This uses a
        // separate cache.
        $projects = $this->getProjects($refresh);
        if (isset($projects[$id])) {
            return $projects[$id];
        }

        // Find the project directly.
        $cacheKey = sprintf('%s:project:%s:%s', self::$sessionId, $id, $host);
        $cached = self::$cache->fetch($cacheKey);
        if ($refresh || !$cached) {
            $scheme = 'https';
            if ($host !== null && (($pos = strpos($host, '//')) !== false)) {
                $scheme = parse_url($host, PHP_URL_SCHEME);
                $host = substr($host, $pos + 2);
            }
            $project = $this->getClient()
                ->getProject($id, $host, $scheme !== 'http');
            if ($project) {
                $toCache = $project->getData();
                $toCache['_endpoint'] = $project->getUri(true);
                self::$cache->save($cacheKey, $toCache, $this->config->get('api.projects_ttl'));
            }
        } else {
            $guzzleClient = $this->getClient()->getConnector()->getClient();
            $baseUrl = $cached['_endpoint'];
            unset($cached['_endpoint']);
            $project = new Project($cached, $baseUrl, $guzzleClient);
        }

        return $project;
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
     * @param string  $id          The environment ID to load.
     * @param Project $project     The project.
     * @param bool|null $refresh   Whether to refresh the list of environments.
     * @param bool $tryMachineName Whether to retry, treating the ID as a
     *                             machine name.
     *
     * @return Environment|false The environment, or false if not found.
     */
    public function getEnvironment($id, Project $project, $refresh = null, $tryMachineName = false)
    {
        // Statically cache not found environments.
        static $notFound = [];
        $cacheKey = $project->id . ':' . $id . ($tryMachineName ? ':mn' : '');
        if (!$refresh && isset($notFound[$cacheKey])) {
            return false;
        }

        $environments = $this->getEnvironments($project, $refresh);
        if (isset($environments[$id])) {
            return $environments[$id];
        }
        if ($tryMachineName) {
            foreach ($environments as $environment) {
                if ($environment->machine_name === $id) {
                    return $environment;
                }
            }
        }

        $notFound[$cacheKey] = true;

        return false;
    }

    /**
     * Get the current user's account info.
     *
     * @param bool $reset
     *
     * @return array
     *   An array containing at least 'uuid', 'mail', and 'display_name'.
     */
    public function getMyAccount($reset = false)
    {
        $cacheKey = sprintf('%s:my-account', self::$sessionId);
        if ($reset || !($info = self::$cache->fetch($cacheKey))) {
            $info = $this->getClient()->getAccountInfo($reset);
            self::$cache->save($cacheKey, $info, $this->config->get('api.users_ttl'));
        }

        return $info;
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
        self::$cache->delete(sprintf('%s:my-account', self::$sessionId));
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

    /**
     * @return bool
     */
    public function isLoggedIn()
    {
        return $this->getClient(false)->getConnector()->isLoggedIn();
    }

    /**
     * Load a project user ("project access" record) by email address.
     *
     * @param Project $project
     * @param string  $email
     *
     * @return ProjectAccess|false
     */
    public function loadProjectAccessByEmail(Project $project, $email)
    {
        foreach ($project->getUsers() as $user) {
            $account = $this->getAccount($user);
            if ($account['email'] === $email) {
                return $user;
            }
        }

        return false;
    }

    /**
     * Returns a project label.
     *
     * @param Project      $project
     * @param string|false $tag
     *
     * @return string
     */
    public function getProjectLabel(Project $project, $tag = 'info')
    {
        $title = $project->title;
        $pattern = $title ? '%2$s (%3$s)' : '%3$s';
        if ($tag !== false) {
            $pattern = $title ? '<%1$s>%2$s</%1$s> (%3$s)' : '<%1$s>%3$s</%1$s>';
        }

        return sprintf($pattern, $tag, $title, $project->id);
    }
}
