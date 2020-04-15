<?php

namespace Platformsh\Cli\Service;

use CommerceGuys\Guzzle\Oauth2\AccessToken;
use Doctrine\Common\Cache\CacheProvider;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\ApiToken\Storage;
use Platformsh\Cli\ApiToken\StorageInterface;
use Platformsh\Cli\CredentialHelper\Manager;
use Platformsh\Cli\CredentialHelper\SessionStorage;
use Platformsh\Cli\Event\EnvironmentsChangedEvent;
use Platformsh\Cli\Model\Route;
use Platformsh\Cli\Util\NestedArrayUtil;
use Platformsh\Client\Connection\Connector;
use Platformsh\Client\Exception\ApiResponseException;
use Platformsh\Client\Model\Deployment\EnvironmentDeployment;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Platformsh\Client\Model\ProjectAccess;
use Platformsh\Client\Model\Resource as ApiResource;
use Platformsh\Client\PlatformClient;
use Platformsh\Client\Session\SessionInterface;
use Platformsh\Client\Session\Storage\File;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Decorates the PlatformClient API client to provide aggressive caching.
 */
class Api
{
    /** @var Config */
    protected $config;

    /** @var \Doctrine\Common\Cache\CacheProvider */
    protected $cache;

    /** @var OutputInterface */
    private $output;

    /** @var OutputInterface */
    private $stdErr;

    /** @var StorageInterface */
    private $apiTokenStorage;

    /** @var EventDispatcherInterface */
    public $dispatcher;

    /** @var string */
    protected $sessionId = 'default';

    /** @var string */
    protected $apiToken = '';

    /** @var string */
    protected $apiTokenType = 'exchange';

    /** @var PlatformClient */
    protected static $client;

    /** @var Environment[] */
    protected static $environmentsCache = [];

    /** @var bool */
    protected static $environmentsCacheRefreshed = false;

    /** @var \Platformsh\Client\Model\Account[] */
    protected static $accountsCache = [];

    /** @var \Platformsh\Client\Model\ProjectAccess[] */
    protected static $projectAccessesCache = [];

    /** @var array */
    protected static $notFound = [];

    /** @var \Platformsh\Client\Session\Storage\SessionStorageInterface|null */
    protected $sessionStorage;

    /**
     * Constructor.
     *
     * @param Config|null $config
     * @param CacheProvider|null $cache
     * @param OutputInterface|null $output
     * @param StorageInterface|null $apiTokenStorage
     * @param EventDispatcherInterface|null $dispatcher
     */
    public function __construct(
        Config $config = null,
        CacheProvider $cache = null,
        OutputInterface $output = null,
        StorageInterface $apiTokenStorage = null,
        EventDispatcherInterface $dispatcher = null
    ) {
        $this->config = $config ?: new Config();
        $this->output = $output ?: new ConsoleOutput();
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput(): $output;
        $this->apiTokenStorage = $apiTokenStorage ?: Storage::factory($this->config);
        $this->dispatcher = $dispatcher ?: new EventDispatcher();

        $this->cache = $cache ?: CacheFactory::createCacheProvider($this->config);

        $this->sessionId = $this->config->getSessionId();
        if (strpos($this->sessionId, 'api-token') === 0) {
            throw new \InvalidArgumentException('Invalid session ID: ' . $this->sessionId);
        }

        if ($this->apiToken === '') {
            // Exchangeable API tokens: a token which is exchanged for a
            // temporary access token.
            if ($this->config->has('api.token_file')) {
                $this->apiToken = $this->loadTokenFromFile($this->config->get('api.token_file'));
                $this->apiTokenType = 'exchange';
                $this->sessionId = 'api-token';
            } elseif ($this->config->has('api.token')) {
                $this->apiToken = $this->config->get('api.token');
                $this->apiTokenType = 'exchange';
                $this->sessionId = 'api-token';
            } elseif ($this->config->has('api.access_token_file') || $this->config->has('api.access_token')) {
                // Permanent, personal access token (deprecated) - an OAuth 2.0
                // bearer token which is used directly in API requests.
                @trigger_error('This type of API token (a permanent access token) is deprecated. Please generate a new API token when possible.', E_USER_DEPRECATED);
                if ($this->config->has('api.access_token_file')) {
                    $this->apiToken = $this->loadTokenFromFile($this->config->get('api.access_token_file'));
                } else {
                    $this->apiToken = $this->config->get('api.access_token');
                }
                $this->apiTokenType = 'access';
            }
        }

        // Ensure a unique session ID per API token.
        if ($this->apiToken !== '') {
            $this->sessionId = 'api-token-' . substr(hash('sha256', $this->apiToken), 0, 8);
        }
    }

    /**
     * @return \Doctrine\Common\Cache\CacheProvider
     */
    public function getCache()
    {
        return $this->cache;
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
     * @param bool $includeStored
     *
     * @return bool
     */
    public function hasApiToken($includeStored = true)
    {
        if (!$includeStored) {
            $apiConfig = $this->config->get('api');

            return isset($apiConfig['token'])
                || isset($apiConfig['token_file'])
                || isset($apiConfig['access_token_file'])
                || isset($apiConfig['access_token']);
        }

        return $this->apiTokenStorage->getToken() !== '';
    }

    /**
     * Checks if any sessions exist (with any session ID).
     *
     * @return bool
     */
    public function anySessionsExist()
    {
        if ($this->sessionStorage instanceof SessionStorage && $this->sessionStorage->hasAnySessions()) {
            return true;
        }
        $dir = $this->config->getSessionDir();
        $files = glob($dir . '/sess-cli-*/*', GLOB_NOSORT);

        return !empty($files);
    }

    /**
     * Logs out of the current session.
     */
    public function logout()
    {
        // Delete the stored API token, if any.
        $this->apiTokenStorage->deleteToken();

        // Log out in the connector (this clears the "session" and attempts
        // to revoke stored tokens).
        $this->getClient(false)->getConnector()->logOut();

        // Clear the cache.
        $this->cache->flushAll();
    }

    /**
     * Deletes all sessions.
     */
    public function deleteAllSessions()
    {
        if ($this->sessionStorage instanceof SessionStorage) {
            $this->sessionStorage->deleteAll();
        }
        $dir = $this->config->getSessionDir();
        if (is_dir($dir)) {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($dir);
        }
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
            $this->config->getVersion(),
            php_uname('s'),
            php_uname('r'),
            PHP_VERSION
        );
    }

    /**
     * @return array
     */
    public function getConnectorOptions() {
        $connectorOptions = [];
        $connectorOptions['accounts'] = rtrim($this->config->get('api.accounts_api_url'), '/') . '/';
        $connectorOptions['verify'] = !$this->config->get('api.skip_ssl');
        $connectorOptions['debug'] = $this->config->get('api.debug') ? STDERR : false;
        $connectorOptions['client_id'] = $this->config->get('api.oauth2_client_id');
        $connectorOptions['user_agent'] = $this->getUserAgent();

        // Load an API token from storage, if there is one saved.
        $storedToken = $this->apiTokenStorage->getToken();
        if ($storedToken !== '') {
            $this->apiToken = $storedToken;
            $this->apiTokenType = 'exchange';
        }
        $connectorOptions['api_token'] = $this->apiToken;
        $connectorOptions['api_token_type'] = $this->apiTokenType;

        $proxy = $this->getProxy();
        if ($proxy !== null) {
            $connectorOptions['proxy'] = $proxy;
        }

        // Override the OAuth 2.0 token and revoke URLs if provided.
        if ($this->config->has('api.oauth2_token_url')) {
            $connectorOptions['token_url'] = $this->config->get('api.oauth2_token_url');
        }
        if ($this->config->has('api.oauth2_revoke_url')) {
            $connectorOptions['revoke_url'] = $this->config->get('api.oauth2_revoke_url');
        }

        $connectorOptions['on_refresh_error'] = function (BadResponseException $e) {
            return $this->onRefreshError($e);
        };

        return $connectorOptions;
    }

    /**
     * Logs out and prompts for re-authentication after a token refresh error.
     *
     * @param BadResponseException $e
     *
     * @return AccessToken|null
     */
    private function onRefreshError(BadResponseException $e) {
        $response = $e->getResponse();
        if ($response && !in_array($response->getStatusCode(), [400, 401])) {
            return null;
        }

        $this->logout();
        $this->stdErr->writeln('<comment>Your session has expired. You have been logged out.</comment>');

        if ($response && $this->stdErr->isVeryVerbose()) {
            $this->stdErr->writeln($e->getMessage() . ApiResponseException::getErrorDetails($response));
        }

        $this->stdErr->writeln('');

        $this->dispatcher->dispatch('login_required');
        $session = $this->getClient(false)->getConnector()->getSession();

        return $this->tokenFromSession($session);
    }

    /**
     * Loads and returns an AccessToken, if possible, from a session.
     *
     * @param SessionInterface $session
     *
     * @return AccessToken|null
     */
    private function tokenFromSession(SessionInterface $session) {
        if (!$accessToken = $session->get('accessToken')) {
            return null;
        }
        $map = [
            'expires' => 'expires',
            'refreshToken' => 'refresh_token',
            'scope' => 'scope',
        ];
        $tokenData = [];
        foreach ($map as $sessionKey => $tokenKey) {
            $value = $session->get($sessionKey);
            if ($value !== false && $value !== null) {
                $tokenData[$tokenKey] = $value;
            }
        }

        return new AccessToken($tokenData['access_token'], $session->get('tokenType') ?: null, $tokenData);
    }

    /**
     * @return array
     */
    public function getGuzzleOptions() {
        $options = [
            'defaults' => [
                'headers' => ['User-Agent' => $this->getUserAgent()],
                'debug' => $this->config->get('api.debug') ? STDERR : false,
                'verify' => !$this->config->get('api.skip_ssl'),
                'proxy' => $this->getProxy(),
            ],
        ];

        if (extension_loaded('zlib')) {
            $options['defaults']['decode_content'] = true;
            $options['defaults']['headers']['Accept-Encoding'] = 'gzip';
        }

        return $options;
    }

    /**
     * Get the API client object.
     *
     * @param bool $autoLogin Whether to log in, if the client is not already
     *                        authenticated (default: true).
     * @param bool $reset     Whether to re-initialize the client.
     *
     * @return PlatformClient
     */
    public function getClient($autoLogin = true, $reset = false)
    {
        if (!isset(self::$client) || $reset) {
            $connector = new Connector($this->getConnectorOptions());

            // Set up a persistent session to store OAuth2 tokens. By default,
            // this will be stored in a JSON file:
            // $HOME/.platformsh/.session/sess-cli-default/sess-cli-default.json
            $session = $connector->getSession();
            $session->setId('cli-' . $this->sessionId);

            $this->initSessionStorage();
            $session->setStorage($this->sessionStorage);

            // Ensure session data is (re-)loaded every time.
            // @todo move this to the Session
            if (!$session->getData()) {
                $session->load(true);
            }

            self::$client = new PlatformClient($connector);

            if ($autoLogin && !$connector->isLoggedIn()) {
                $this->dispatcher->dispatch('login_required');
            }

            try {
                $connector->getClient()->getEmitter()->on('error', function (ErrorEvent $event) {
                    if ($event->getResponse() && $event->getResponse()->getStatusCode() === 403) {
                        $this->on403($event);
                    }
                });
            } catch (\RuntimeException $e) {
                // Ignore errors if the user is not logged in at this stage.
            }
        }

        return self::$client;
    }

    /**
     * Initializes session credential storage.
     */
    private function initSessionStorage() {
        // Attempt to use the docker-credential-helpers.
        $manager = new Manager($this->config);
        if ($manager->isSupported()) {
            $manager->install();
            $this->sessionStorage = new SessionStorage($manager, $this->config->get('application.slug'));
            return;
        }

        // Fall back to file storage.
        $this->sessionStorage = new File($this->config->getSessionDir());
    }

    /**
     * Finds a proxy address based on the http_proxy or https_proxy environment variables.
     *
     * @return string|array|null
     */
    private function getProxy() {
        // The proxy variables should be ignored in a non-CLI context.
        if (PHP_SAPI !== 'cli') {
            return null;
        }
        $proxies = [];
        foreach (['https', 'http'] as $scheme) {
            $proxies[$scheme] = str_replace($scheme . '://', 'tcp://', getenv($scheme . '_proxy'));
        }
        $proxies = array_filter($proxies);
        if (count($proxies)) {
            return count($proxies) === 1 ? reset($proxies) : $proxies;
        }

        return null;
    }

    /**
     * Constructs a stream context for using the API with stream functions.
     *
     * @return resource
     */
    public function getStreamContext() {
        $opts = [
            'http' => [
                'method' => 'GET',
                'follow_location' => 0,
                'timeout' => 15,
                'user_agent' => $this->getUserAgent(),
                'header' => [
                    'Authorization: Bearer ' . $this->getAccessToken(),
                ],
            ],
        ];
        $proxy = $this->getProxy();
        if (is_array($proxy)) {
            if (isset($proxy['https'])) {
                $opts['http']['proxy'] = $proxy['https'];
            } elseif (isset($proxy['http'])) {
                $opts['http']['proxy'] = $proxy['http'];
            }
        } elseif (is_string($proxy) && $proxy !== '') {
            $opts['http']['proxy'] = $proxy;
        }

        return stream_context_create($opts);
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
        $cacheKey = sprintf('%s:projects', $this->sessionId);

        /** @var Project[] $projects */
        $projects = [];

        $cached = $this->cache->fetch($cacheKey);

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

            $this->cache->save($cacheKey, $cachedProjects, $this->config->get('api.projects_ttl'));
        } else {
            $guzzleClient = $this->getHttpClient();
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
            if ($host === null || stripos(parse_url($projects[$id]->getUri(), PHP_URL_HOST), $host) !== false) {
                return $projects[$id];
            }
        }

        // Find the project directly.
        $cacheKey = sprintf('%s:project:%s:%s', $this->sessionId, $id, $host);
        $cached = $this->cache->fetch($cacheKey);
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
                $this->cache->save($cacheKey, $toCache, $this->config->get('api.projects_ttl'));
            }
        } else {
            $guzzleClient = $this->getHttpClient();
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
     * @return Environment[] The user's environments, keyed by ID.
     */
    public function getEnvironments(Project $project, $refresh = null, $events = true)
    {
        $projectId = $project->id;

        if (!$refresh && isset(self::$environmentsCache[$projectId])) {
            return self::$environmentsCache[$projectId];
        }

        $cacheKey = 'environments:' . $projectId;
        $cached = $this->cache->fetch($cacheKey);

        if ($refresh === false && !$cached) {
            return [];
        } elseif ($refresh || !$cached) {
            $environments = [];
            $toCache = [];
            foreach ($project->getEnvironments() as $environment) {
                $environments[$environment->id] = $environment;
                $toCache[$environment->id] = $environment->getData();
            }

            // Dispatch an event if the list of environments has changed.
            if ($events && (!$cached || array_diff_key($environments, $cached))) {
                $this->dispatcher->dispatch(
                    'environments_changed',
                    new EnvironmentsChangedEvent($project, $environments)
                );
            }

            $this->cache->save($cacheKey, $toCache, $this->config->get('api.environments_ttl'));
            self::$environmentsCacheRefreshed = true;
        } else {
            $environments = [];
            $endpoint = $project->getUri();
            $guzzleClient = $this->getHttpClient();
            foreach ((array) $cached as $id => $data) {
                $environments[$id] = new Environment($data, $endpoint, $guzzleClient, true);
            }
        }

        self::$environmentsCache[$projectId] = $environments;

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
        $cacheKey = $project->id . ':' . $id . ($tryMachineName ? ':mn' : '');
        if (!$refresh && isset(self::$notFound[$cacheKey])) {
            return false;
        }

        $environments = $this->getEnvironments($project, $refresh);

        // Look for the environment by ID.
        if (isset($environments[$id])) {
            return $environments[$id];
        }

        // Retry directly if the environment was not found in the cache.
        if ($refresh === null) {
            if ($environment = $project->getEnvironment($id)) {
                // If the environment was found directly, the cache must be out
                // of date.
                $this->clearEnvironmentsCache($project->id);
                return $environment;
            }
        }

        // Look for the environment by machine name.
        if ($tryMachineName) {
            foreach ($environments as $environment) {
                if ($environment->machine_name === $id) {
                    return $environment;
                }
            }
        }

        self::$notFound[$cacheKey] = true;

        return false;
    }

    /**
     * Get the current user's account info.
     *
     * @param bool $reset
     *
     * @return array
     *   An array containing at least 'username', 'id', 'mail', and
     *   'display_name'.
     */
    public function getMyAccount($reset = false)
    {
        $cacheKey = sprintf('%s:my-account', $this->sessionId);
        if ($reset || !($info = $this->cache->fetch($cacheKey))) {
            $info = $this->getClient()->getAccountInfo($reset);
            $this->cache->save($cacheKey, $info, $this->config->get('api.users_ttl'));
        }

        return $info;
    }

    /**
     * Get a user's account info.
     *
     * @param ProjectAccess $access
     * @param bool          $reset
     *
     * @return array
     *   An array containing 'email' and 'display_name'.
     */
    public function getAccount(ProjectAccess $access, $reset = false)
    {
        if (isset(self::$accountsCache[$access->id]) && !$reset) {
            return self::$accountsCache[$access->id];
        }

        $cacheKey = 'account:' . $access->id;
        if ($reset || !($details = $this->cache->fetch($cacheKey))) {
            $data = $access->getData();
            // Use embedded user information if possible.
            if (isset($data['_embedded']['users'][0]) && count($data['_embedded']['users']) === 1) {
                $details = $data['_embedded']['users'][0];
                self::$accountsCache[$access->id] = $details;
            } else {
                $details = $access->getAccount()->getProperties();
                $this->cache->save($cacheKey, $details, $this->config->get('api.users_ttl'));
                self::$accountsCache[$access->id] = $details;
            }
        }

        return $details;
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
        $this->cache->delete('environments:' . $projectId);
        unset(self::$environmentsCache[$projectId]);
        foreach (array_keys(self::$notFound) as $key) {
            if (strpos($key, $projectId . ':') === 0) {
                unset(self::$notFound[$key]);
            }
        }
    }

    /**
     * Clear the projects cache.
     */
    public function clearProjectsCache()
    {
        $this->cache->delete(sprintf('%s:projects', $this->sessionId));
        $this->cache->delete(sprintf('%s:my-account', $this->sessionId));
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
            throw new \InvalidArgumentException(sprintf(
                'Invalid path "%s": the property "%s" is not an array.',
                $propertyPath,
                $propertyName
            ));
        }
        $value = NestedArrayUtil::getNestedArrayValue($property, $parents, $keyExists);
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
     * Load project users ("project access" records).
     *
     * @param \Platformsh\Client\Model\Project $project
     * @param bool                             $reset
     *
     * @return ProjectAccess[]
     */
    public function getProjectAccesses(Project $project, $reset = false)
    {
        if ($reset || !isset(self::$projectAccessesCache[$project->id])) {
            self::$projectAccessesCache[$project->id] = $project->getUsers();
        }

        return self::$projectAccessesCache[$project->id];
    }

    /**
     * Load a project user ("project access" record) by email address.
     *
     * @param Project $project
     * @param string  $email
     * @param bool    $reset
     *
     * @return ProjectAccess|false
     */
    public function loadProjectAccessByEmail(Project $project, $email, $reset = false)
    {
        foreach ($this->getProjectAccesses($project, $reset) as $user) {
            $account = $this->getAccount($user);
            if ($account['email'] === $email || strtolower($account['email']) === strtolower($email)) {
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
        $pattern = strlen($title) > 0 ? '%2$s (%3$s)' : '%3$s';
        if ($tag !== false) {
            $pattern = strlen($title) > 0 ? '<%1$s>%2$s</%1$s> (%3$s)' : '<%1$s>%3$s</%1$s>';
        }

        return sprintf($pattern, $tag, $title, $project->id);
    }

    /**
     * Returns an environment label.
     *
     * @param Environment  $environment
     * @param string|false $tag
     *
     * @return string
     */
    public function getEnvironmentLabel(Environment $environment, $tag = 'info')
    {
        $id = $environment->id;
        $title = $environment->title;
        $use_title = strlen($title) > 0 && $title !== $id;
        $pattern = $use_title ? '%2$s (%3$s)' : '%3$s';
        if ($tag !== false) {
            $pattern = $use_title ? '<%1$s>%2$s</%1$s> (%3$s)' : '<%1$s>%3$s</%1$s>';
        }

        return sprintf($pattern, $tag, $title, $id);
    }

    /**
     * Get a resource, matching on the beginning of the ID.
     *
     * @param string        $id
     * @param ApiResource[] $resources
     * @param string        $name
     *
     * @return ApiResource
     *   The resource, if one (and only one) is matched.
     */
    public function matchPartialId($id, array $resources, $name = 'Resource')
    {
        $matched = array_filter($resources, function (ApiResource $resource) use ($id) {
            return strpos($resource->getProperty('id'), $id) === 0;
        });

        if (count($matched) > 1) {
            $matchedIds = array_map(function (ApiResource $resource) {
                return $resource->getProperty('id');
            }, $matched);
            throw new \InvalidArgumentException(sprintf(
                'The partial ID "<error>%s</error>" is ambiguous; it matches the following %s IDs: %s',
                $id,
                strtolower($name),
                "\n  " . implode("\n  ", $matchedIds)
            ));
        } elseif (count($matched) === 0) {
            throw new \InvalidArgumentException(sprintf('%s not found: "<error>%s</error>"', $name, $id));
        }

        return reset($matched);
    }

    /**
     * Returns the OAuth 2 access token.
     *
     * @return string
     */
    public function getAccessToken()
    {
        // Check for legacy API tokens.
        if ($this->apiToken !== '' && $this->apiTokenType === 'access') {
            return $this->apiToken;
        }

        // Get the access token from the session.
        $session = $this->getClient()->getConnector()->getSession();
        $token = $session->get('accessToken');
        $expires = $session->get('expires');

        // If there is no token, or it has expired, make an API request, which
        // automatically obtains a token and saves it to the session.
        if (!$token || $expires < time()) {
            $this->getMyAccount(true);
            if (!$token = $session->get('accessToken')) {
                throw new \RuntimeException('No access token found');
            }
        }

        return $token;
    }

    /**
     * Sort URLs, preferring shorter ones with HTTPS.
     *
     * @param string $a
     * @param string $b
     *
     * @return int
    */
    public function urlSort($a, $b)
    {
        $result = 0;
        foreach ([$a, $b] as $key => $url) {
            if (parse_url($url, PHP_URL_SCHEME) === 'https') {
                $result += $key === 0 ? -2 : 2;
            }
        }
        $result += strlen($a) <= strlen($b) ? -1 : 1;

        return $result;
    }

    /**
     * Get the authenticated HTTP client.
     *
     * @return ClientInterface
     */
    public function getHttpClient()
    {
        return $this->getClient()->getConnector()->getClient();
    }

    /**
     * Get the current deployment for an environment.
     *
     * @param Environment $environment
     * @param bool        $refresh
     *
     * @return EnvironmentDeployment
     */
    public function getCurrentDeployment(Environment $environment, $refresh = false)
    {
        $cacheKey = implode(':', ['current-deployment', $environment->project, $environment->id, $environment->head_commit]);
        $data = $this->cache->fetch($cacheKey);
        if ($data === false || $refresh) {
            $deployment = $environment->getCurrentDeployment();
            $data = $deployment->getData();
            $data['_uri'] = $deployment->getUri();
            $this->cache->save($cacheKey, $data);
        } else {
            $deployment = new EnvironmentDeployment($data, $data['_uri'], $this->getHttpClient(), true);
        }

        return $deployment;
    }

    /**
     * Returns whether the current deployment for an environment is already cached.
     *
     * @param Environment $environment
     *
     * @return bool
     */
    public function hasCachedCurrentDeployment(Environment $environment)
    {
        $cacheKey = implode(':', ['current-deployment', $environment->project, $environment->id, $environment->head_commit]);

        return $this->cache->contains($cacheKey);
    }

    /**
     * Get the default environment in a list.
     *
     * @param array $environments An array of environments, keyed by ID.
     *
     * @return string|null
     */
    public function getDefaultEnvironmentId(array $environments)
    {
        // If there is only one environment, use that.
        if (count($environments) <= 1) {
            $environment = reset($environments);

            return $environment ? $environment->id : null;
        }

        // Check if there is only one "main" environment.
        $main = array_filter($environments, function (Environment $environment) {
            return $environment->is_main;
        });
        if (count($main) === 1) {
            $environment = reset($main);

            return $environment ? $environment->id : null;
        }

        // Check if there is a "master" environment.
        if (isset($environments['master'])) {
            return 'master';
        }

        return null;
    }

    /**
     * Get the preferred site URL for an environment and app.
     *
     * @param \Platformsh\Client\Model\Environment                           $environment
     * @param string                                                         $appName
     * @param \Platformsh\Client\Model\Deployment\EnvironmentDeployment|null $deployment
     *
     * @return string|null
     */
    public function getSiteUrl(Environment $environment, $appName, EnvironmentDeployment $deployment = null)
    {
        $deployment = $deployment ?: $this->getCurrentDeployment($environment);
        $routes = Route::fromDeploymentApi($deployment->routes);
        $appUrls = [];
        foreach ($routes as $route) {
            if ($route->type === 'upstream' && $route->getUpstreamName() === $appName) {
                // Use the primary route, if it matches this app.
                if ($route->primary) {
                    return $route->url;
                }

                $appUrls[] = $route->url;
            }
        }
        usort($appUrls, [$this, 'urlSort']);
        $siteUrl = reset($appUrls);
        if ($siteUrl) {
            return $siteUrl;
        }
        if ($environment->hasLink('public-url')) {
            return $environment->getLink('public-url');
        }

        return null;
    }

    /**
     * React on an API 403 request.
     *
     * @param \GuzzleHttp\Event\ErrorEvent $event
     */
    private function on403(ErrorEvent $event)
    {
        $url = $event->getRequest()->getUrl();
        $path = parse_url($url, PHP_URL_PATH);
        if ($path && strpos($path, '/api/projects/') === 0) {
            // Clear the environments cache for environment request errors.
            if (preg_match('#^/api/projects/([^/]+?)/environments/#', $path, $matches)) {
                $this->clearEnvironmentsCache($matches[1]);
            }
            // Clear the projects cache for other project request errors.
            if (preg_match('#^/api/projects/([^/]+?)[/$]/#', $path, $matches)) {
                $this->clearProjectsCache();
            }
        }
    }
}
