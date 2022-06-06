<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Doctrine\Common\Cache\CacheProvider;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use League\OAuth2\Client\Token\AccessToken;
use Platformsh\Cli\CredentialHelper\Manager;
use Platformsh\Cli\CredentialHelper\SessionStorage;
use Platformsh\Cli\Event\EnvironmentsChangedEvent;
use Platformsh\Cli\Event\LoginRequiredEvent;
use Platformsh\Cli\Model\Route;
use Platformsh\Cli\Util\NestedArrayUtil;
use Platformsh\Client\Connection\Connector;
use Platformsh\Client\Model\ApiResourceBase;
use Platformsh\Client\Exception\ApiResponseException;
use Platformsh\Client\Model\Deployment\EnvironmentDeployment;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Organization\Organization;
use Platformsh\Client\Model\Project;
use Platformsh\Client\Model\ProjectAccess;
use Platformsh\Client\PlatformClient;
use Platformsh\Client\Model\ProjectStub;
use Platformsh\Client\Model\SshKey;
use Platformsh\Client\Model\Subscription;
use Platformsh\Client\Model\User;
use Platformsh\Client\Session\Session;
use Platformsh\Client\Session\SessionInterface;
use Platformsh\Client\Session\Storage\File;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Decorates the PlatformClient API client to provide aggressive caching.
 */
class Api
{
    /** @var EventDispatcherInterface */
    public $dispatcher;

    /** @var Config */
    private $config;

    /** @var \Doctrine\Common\Cache\CacheProvider */
    private $cache;

    /** @var OutputInterface */
    private $stdErr;

    /** @var TokenConfig */
    private $tokenConfig;

    /**
     * The library's API client object.
     *
     * This is static so that a freshly logged-in client can then be reused by a parent command with a different service container.
     *
     * @var PlatformClient
     */
    private static $client;

    /**
     * A cache of environments lists, keyed by project ID.
     *
     * @var string<Environment[]>
     */
    private static $environmentsCache = [];

    /**
     * A cache of account details arrays, keyed by project ID.
     *
     * @see Api::getAccount()
     *
     * @var string<array>
     */
    private static $accountsCache = [];

    /**
     * A cache of project access lists, keyed by project ID.
     *
     * @see Api::getProjectAccesses()
     *
     * @var string<\Platformsh\Client\Model\ProjectAccess[]>
     */
    private static $projectAccessesCache = [];

    /**
     * A cache of not-found environment IDs.
     *
     * @see Api::getEnvironment()
     *
     * @var string[]
     */
    private static $notFound = [];

    /**
     * Session storage, via files or credential helpers.
     *
     * @see Api::initSessionStorage()
     *
     * @var \Platformsh\Client\Session\Storage\SessionStorageInterface|null
     */
    private $sessionStorage;

    /**
     * Sets whether we are currently verifying login using a test request.
     *
     * @var bool
     */
    public $inLoginCheck = false;

    public function __construct(
        OutputInterface $output,
        Config $config,
        CacheProvider $cache,
        TokenConfig $tokenConfig
    ) {
        $this->config = $config;
        $this->dispatcher = new EventDispatcher();
        $this->cache = $cache;
        $this->tokenConfig = $tokenConfig;
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    public function withConfigOverrides(array $overrides): self
    {
        $api = clone $this;
        $api->config = $api->config->withOverrides($overrides);
        return $api;
    }

    /**
     * Sets up listeners (called by the DI container).
     *
     * @required
     *
     * @param \Platformsh\Cli\Service\AutoLoginListener $autoLoginListener
     * @param \Platformsh\Cli\Service\DrushAliasUpdater $drushAliasUpdater
     */
    public function injectListeners(
        AutoLoginListener $autoLoginListener,
        DrushAliasUpdater $drushAliasUpdater
    ): void
    {
        $this->dispatcher->addListener(
            'login.required',
            [$autoLoginListener, 'onLoginRequired']
        );
        $this->dispatcher->addListener(
            'environments.changed',
            [$drushAliasUpdater, 'onEnvironmentsChanged']
        );
    }

    /**
     * Get the cache object.
     *
     * @return \Doctrine\Common\Cache\CacheProvider
     */
    public function getCache(): CacheProvider
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
    protected function loadTokenFromFile(string $filename): string
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
        return $this->tokenConfig->getAccessToken() || $this->tokenConfig->getApiToken($includeStored);
    }

    /**
     * Lists existing sessions.
     *
     * Excludes API-token-specific session IDs.
     *
     * @return string[]
     */
    public function listSessionIds()
    {
        $ids = [];
        if ($this->sessionStorage instanceof SessionStorage) {
            $ids = $this->sessionStorage->listSessionIds();
        }
        $dir = $this->config->getSessionDir();
        $files = glob($dir . '/sess-cli-*', GLOB_NOSORT);
        foreach ($files as $file) {
            if (\preg_match('@/sess-cli-([a-z0-9_-]+)@i', $file, $matches)) {
                $ids[] = $matches[1];
            }
        }
        $ids = \array_filter($ids, function ($id) {
           return strpos($id, 'api-token-') !== 0;
        });

        return \array_unique($ids);
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
        $this->tokenConfig->storage()->deleteToken();

        // Log out in the connector (this clears the "session" and attempts
        // to revoke stored tokens).
        $this->getClient(false)->getConnector()->logOut();

        // Clear the cache.
        $this->cache->flushAll();

        // Ensure the session directory is wiped.
        $dir = $this->config->getSessionDir(true);
        if (is_dir($dir)) {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($dir);
        }
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
     * Returns options to instantiate an API client library Connector.
     *
     * @see Connector::__construct()
     *
     * @return array
     */
    private function getConnectorOptions() {
        $connectorOptions = [];
        $connectorOptions['api_url'] = $this->config->getWithDefault('api.base_url', '');
        if ($this->config->has('api.accounts_api_url')) {
            $connectorOptions['accounts'] = $this->config->get('api.accounts_api_url');
        }
        $connectorOptions['certifier_url'] = $this->config->get('api.certifier_url');
        $connectorOptions['verify'] = $this->config->get('api.skip_ssl') ? false : $this->caBundlePath();

        $connectorOptions['debug'] = $this->config->get('api.debug') ? STDERR : false;
        $connectorOptions['client_id'] = $this->config->get('api.oauth2_client_id');
        $connectorOptions['user_agent'] = $this->config->getUserAgent();
        $connectorOptions['timeout'] = $this->config->get('api.default_timeout');

        if ($apiToken = $this->tokenConfig->getApiToken()) {
            $connectorOptions['api_token'] = $apiToken;
            $connectorOptions['api_token_type'] = 'exchange';
        } elseif ($accessToken = $this->tokenConfig->getAccessToken()) {
            $connectorOptions['api_token'] = $accessToken;
            $connectorOptions['api_token_type'] = 'access';
        }

        $guzzleOptions = $this->getGuzzleOptions();
        if (!empty($guzzleOptions['defaults']['proxy'])) {
            $connectorOptions['proxy'] = $guzzleOptions['defaults']['proxy'];
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
     * Returns the path to the CA bundle or file detected by Composer.
     *
     * Composer stores the path statically, so this function can be run
     * multiple times safely. If a system CA bundle cannot be detected,
     * Composer will use its bundled file. It will copy the file to a
     * temporary directory if necessary (when running inside a Phar).
     *
     * @return string
     */
    private function caBundlePath()
    {
        return \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath(new ConsoleLogger($this->stdErr));
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
        if ($this->inLoginCheck) {
            return null;
        }

        $this->logout();

        $body = (string) $e->getRequest()->getBody();
        \parse_str($body, $parsed);
        if (isset($parsed['grant_type']) && $parsed['grant_type'] === 'api_token') {
            $this->stdErr->writeln('<comment>The API token is invalid.</comment>');
        } else {
            $this->stdErr->writeln('<comment>Your session has expired. You have been logged out.</comment>');
        }

        if ($response && $this->stdErr->isVeryVerbose()) {
            $this->stdErr->writeln($e->getMessage() . ApiResponseException::getErrorDetails($response));
        }

        $this->stdErr->writeln('');

        $this->dispatcher->dispatch(new LoginRequiredEvent(), 'login_required');
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
        if (!$session->get('accessToken')) {
            return null;
        }
        $map = [
            'accessToken' => 'access_token',
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

        return new AccessToken($tokenData);
    }

    /**
     * Returns configuration options for instantiating a Guzzle HTTP client.
     *
     * @see Client::__construct()
     *
     * @return array
     */
    public function getGuzzleOptions() {
        $options = [
            'defaults' => [
                'headers' => ['User-Agent' => $this->config->getUserAgent()],
                'debug' => $this->config->get('api.debug') ? STDERR : false,
                'verify' => $this->config->get('api.skip_ssl') ? false : $this->caBundlePath(),
                'proxy' => array_map(function($proxyUrl) {
                    // If Guzzle is going to use PHP's built-in HTTP streams,
                    // rather than curl, then transform the proxy scheme.
                    if (!\extension_loaded('curl') && \ini_get('allow_url_fopen')) {
                        return \str_replace(['http://', 'https://'], ['tcp://', 'tcp://'], $proxyUrl);
                    }
                    return $proxyUrl;
                }, $this->config->getProxies()),
                'timeout' => $this->config->get('api.default_timeout'),
            ],
        ];

        if (extension_loaded('zlib')) {
            $options['defaults']['decode_content'] = true;
            $options['defaults']['headers']['Accept-Encoding'] = 'gzip';
        }

        return $options;
    }

    /**
     * Returns the API client object.
     *
     * @param bool $autoLogin Whether to log in, if the client is not already
     *                        authenticated (default: true).
     * @param bool $reset     Whether to re-initialize the client.
     *
     * @return PlatformClient
     */
    public function getClient(bool $autoLogin = true, bool $reset = false): PlatformClient
    {
        if (!isset(self::$client) || $reset) {
            $options = $this->getConnectorOptions();

            // Set up a persistent session to store OAuth2 tokens. By default,
            // this will be stored in a JSON file:
            // $HOME/.platformsh/.session/sess-cli-default/sess-cli-default.json
            $sessionId = $this->config->getSessionId();

            // Override the session ID if an API token is set.
            // This ensures file storage from other credentials will not be
            // reused.
            if (!empty($options['api_token'])) {
                $sessionId = 'api-token-' . \substr(\hash('sha256', $options['api_token']), 0, 32);
            }

            $session = new Session($sessionId);
            $this->initSessionStorage();

            // Don't use any storage for the session if an access token is set.
            if (!isset($options['api_token']) || $options['api_token_type'] !== 'access') {
                $session->setStorage($this->sessionStorage);
            }

            $connector = new Connector($options, $session);
            self::$client = new PlatformClient($connector);

            if ($autoLogin && !$connector->isLoggedIn()) {
                $this->dispatcher->dispatch(new LoginRequiredEvent(), 'login.required');
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
            $this->sessionStorage = new SessionStorage($manager, $this->config->get('application.slug'), $this->config);
            return;
        }

        // Fall back to file storage.
        $this->sessionStorage = new File($this->config->getSessionDir());
    }

    /**
     * Constructs a stream context for using the API with stream functions.
     *
     * @param int|float $timeout
     *
     * @return resource
     */
    public function getStreamContext($timeout = 15) {
        $opts = $this->config->getStreamContextOptions($timeout);
        $opts['http']['header'] = [
            'Authorization: Bearer ' . $this->getAccessToken(),
        ];

        return \stream_context_create($opts);
    }

    /**
     * Return the user's projects.
     *
     * @param bool|null $refresh Whether to refresh the list of projects.
     *
     * @return Project[] The user's projects, keyed by project ID.
     */
    public function getProjects(?bool $refresh = null)
    {
        $cacheKey = sprintf('%s:projects', $this->config->getSessionId());

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
            $apiUrl = $this->config->getWithDefault('api.base_url', '');
            foreach ((array) $cached as $id => $data) {
                $projects[$id] = new Project($data, $data['_endpoint'], $guzzleClient);
                if ($apiUrl) {
                    $projects[$id]->setApiUrl($apiUrl);
                }
            }
        }

        return $projects;
    }

    /**
     * Returns the logged-in user's project stubs.
     *
     * @param bool $refresh
     *
     * @return ProjectStub[]
     */
    public function getProjectStubs($refresh = null)
    {
        $cacheKey = sprintf('%s:project-stubs', $this->config->getSessionId());
        $cached = $this->cache->fetch($cacheKey);

        $guzzleClient = $this->getHttpClient();
        $apiUrl = $this->config->getWithDefault('api.base_url', '');

        if ($refresh === false && !$cached) {
            return [];
        } elseif ($refresh || !$cached) {
            $data = $this->getClient()->getAccountInfo($refresh);
            $stubs = ProjectStub::wrapCollection($data, $apiUrl, $guzzleClient);
            $cacheData = [
                'projects' => array_map(function (ProjectStub $stub) { return $stub->getData(); }, $stubs)
            ];
            $this->cache->save($cacheKey, $cacheData, $this->config->get('api.projects_ttl'));
        } else {
            $stubs = ProjectStub::wrapCollection($cached, $apiUrl, $guzzleClient);
        }

        return $stubs;
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
    public function getProject(string $id, ?string $host = null, ?bool $refresh = null)
    {
        // Find the project in the user's main project list. This uses a
        // separate cache.
        $projects = $this->getProjects($refresh);
        if (isset($projects[$id]) && !$this->hostConflicts($projects[$id], $host)) {
            $project = $projects[$id];
            if ($apiUrl = $this->config->getWithDefault('api.base_url', '')) {
                $project->setApiUrl($apiUrl);
            }

            return $project;
        }

        // Find the project directly.
        $cacheKey = sprintf('%s:project:%s:%s', $this->config->getSessionId(), $id, $host);
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
            } else {
                return false;
            }
        } else {
            $guzzleClient = $this->getHttpClient();
            $baseUrl = $cached['_endpoint'];
            unset($cached['_endpoint']);
            $project = new Project($cached, $baseUrl, $guzzleClient);
        }
        $apiUrl = $this->config->getWithDefault('api.base_url', '');
        if ($apiUrl) {
            $project->setApiUrl($apiUrl);
        }

        return $project;
    }

    /**
     * Checks whether a project matches the given hostname.
     *
     * Used to validate whether a project cached against an ID has a
     * conflicting hostname (IDs can be the same on different hosts).
     *
     * @param Project $project
     * @param string|null $host
     *
     * @return bool
     */
    private function hostConflicts(Project $project, $host)
    {
        if ($host !== null && $project->hasProperty('endpoint', false)) {
            $projectHost = \parse_url($project->getProperty('endpoint', true, false), PHP_URL_HOST);
            return \stripos($projectHost, $host) === false;
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
     * @return Environment[] The user's environments, keyed by ID.
     */
    public function getEnvironments(Project $project, ?bool $refresh = null, ?bool $events = true): array
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
                    new EnvironmentsChangedEvent($project, $environments),
                    'environments.changed'
                );
            }

            $this->cache->save($cacheKey, $toCache, $this->config->get('api.environments_ttl'));
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
    public function getEnvironment(string $id, Project $project, ?bool $refresh = null, ?bool $tryMachineName = false)
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

        // Look for the environment by machine name.
        if ($tryMachineName) {
            foreach ($environments as $environment) {
                if ($environment->machine_name === $id) {
                    return $environment;
                }
            }
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

        self::$notFound[$cacheKey] = true;

        return false;
    }

    /**
     * Get the current user's account info.
     *
     * @param bool $reset
     *
     * @deprecated use getUser() if the Auth API (the config key api.auth) is enabled
     *
     * @return array
     *   An array containing at least 'username', 'id', 'mail', and
     *   'display_name'.
     */
    public function getMyAccount(bool $reset = false): array
    {
        $cacheKey = sprintf('%s:my-account', $this->config->getSessionId());
        if ($reset || !($info = $this->cache->fetch($cacheKey))) {
            $info = $this->getClient()->getAccountInfo($reset);
            $this->cache->save($cacheKey, $info, $this->config->get('api.users_ttl'));
        }

        return $info;
    }

    /**
     * Returns the ID of the current user.
     *
     * @return string
     */
    public function getMyUserId($reset = false)
    {
        if ($this->authApiEnabled()) {
            return $this->getUser(null, $reset)->id;
        }
        return $this->getMyAccount($reset)['id'];
    }

    /**
     * Determines if the Auth API can be used, e.g. the getUser() method.
     *
     * @return bool
     */
    public function authApiEnabled()
    {
        return $this->config->getWithDefault('api.auth', false) && $this->config->getWithDefault('api.base_url', '');
    }

    /**
     * Get the logged-in user's SSH keys.
     *
     * @param bool $reset
     *
     * @return SshKey[]
     */
    public function getSshKeys($reset = false)
    {
        $data = $this->getMyAccount($reset);

        return SshKey::wrapCollection($data['ssh_keys'], rtrim($this->config->get('api.base_url'), '/') . '/', $this->getHttpClient());
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
    public function getAccount(ProjectAccess $access, bool $reset = false): array
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
     * Get user information.
     *
     * This is from the /users API which deals with basic authentication-related data.
     *
     * @see Api::authApiEnabled()
     *
     * @param string|null $id
     *   The user ID. Defaults to the current user.
     * @param bool $reset
     *
     * @return User
     */
    public function getUser($id = null, $reset = false)
    {
        if (!$this->config->getWithDefault('api.auth', false)) {
            throw new \BadMethodCallException('api.auth must be enabled for this method');
        }
        if ($id) {
            $cacheKey = 'user:' . $id;
        } else {
            $id = 'me';
            $cacheKey = sprintf('%s:me', $this->config->getSessionId());
        }
        if ($reset || !($data = $this->cache->fetch($cacheKey))) {
            $user = $this->getClient()->getUser($id);
            if (!$user) {
                throw new \InvalidArgumentException('User not found: ' . $id);
            }
            $this->cache->save($cacheKey, $user->getData(), $this->config->get('api.users_ttl'));
        } else {
            $connector = $this->getClient()->getConnector();
            $user = new User($data, $connector->getApiUrl() . '/users', $connector->getClient());
        }
        return $user;
    }

    /**
     * Clear the environments cache for a project.
     *
     * Use this after creating/deleting/updating environment(s).
     *
     * @param string $projectId
     */
    public function clearEnvironmentsCache(string $projectId): void
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
    public function clearProjectsCache(): void
    {
        $this->cache->delete(sprintf('%s:projects', $this->config->getSessionId()));
        $this->cache->delete(sprintf('%s:my-account', $this->config->getSessionId()));
    }

    /**
     * Sort resources.
     *
     * @param ApiResourceBase[] &$resources
     * @param string        $propertyPath
     * @param bool          $reverse
     *
     * @return ApiResourceBase[]
     */
    public static function sortResources(array &$resources, string $propertyPath, bool $reverse = false): array
    {
        uasort($resources, function (ApiResourceBase $a, ApiResourceBase $b) use ($propertyPath, $reverse) {
            $valueA = static::getNestedProperty($a, $propertyPath, false);
            $valueB = static::getNestedProperty($b, $propertyPath, false);
            $cmp = 0;

            switch (gettype($valueA)) {
                case 'string':
                    $cmp = strcasecmp($valueA, $valueB);
                    break;

                case 'integer':
                case 'double':
                case 'boolean':
                    $cmp = $valueA - $valueB;
                    break;
            }

            return $reverse ? -$cmp : $cmp;
        });

        return $resources;
    }

    /**
     * Get a nested property of a resource, via a dot-separated string path.
     *
     * @param ApiResourceBase $resource
     * @param string          $propertyPath
     * @param bool            $lazyLoad
     *
     * @throws \InvalidArgumentException if the property is not found.
     *
     * @return mixed
     */
    public static function getNestedProperty(ApiResourceBase $resource, string $propertyPath, bool $lazyLoad = true)
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
    public function isLoggedIn(): bool
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
    public function getProjectAccesses(Project $project, bool $reset = false): array
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
    public function loadProjectAccessByEmail(Project $project, string $email, bool $reset = false)
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
    public function getProjectLabel(Project $project, $tag = 'info'): string
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
        $type = $environment->getProperty('type', false);
        $use_title = strlen($title) > 0 && $title !== $id;
        $use_type = $type !== null && $type !== $id;
        $pattern = $use_title ? '%2$s (%3$s)' : '%3$s';
        if ($tag !== false) {
            $pattern = $use_title ? '<%1$s>%2$s</%1$s> (%3$s)' : '<%1$s>%3$s</%1$s>';
        }
        if ($use_type) {
            $pattern .= $tag !== false ? ' (type: <%1$s>%4$s</%1$s>)' : ' (type: %4$s)';
        }

        return sprintf($pattern, $tag, $title, $id, $type);
    }

    /**
     * Returns an organization label.
     *
     * @param Organization $organization
     * @param string|false $tag
     *
     * @return string
     */
    public function getOrganizationLabel(Organization $organization, $tag = 'info')
    {
        $name = $organization->name;
        $label = $organization->label;
        $use_label = strlen($label) > 0 && $label !== $name;
        $pattern = $use_label ? '%2$s (%3$s)' : '%3$s';
        if ($tag !== false) {
            $pattern = $use_label ? '<%1$s>%2$s</%1$s> (%3$s)' : '<%1$s>%3$s</%1$s>';
        }

        return sprintf($pattern, $tag, $label, $name);
    }

    /**
     * Get a resource, matching on the beginning of the ID.
     *
     * @param string            $id
     * @param ApiResourceBase[] $resources
     * @param string            $name
     *
     * @return ApiResourceBase
     *   The resource, if one (and only one) is matched.
     */
    public function matchPartialId(string $id, array $resources, string $name = 'Resource'): ApiResourceBase
    {
        $matched = array_filter($resources, function (ApiResourceBase $resource) use ($id) {
            return strpos($resource->getProperty('id'), $id) === 0;
        });

        if (count($matched) > 1) {
            $matchedIds = array_map(function (ApiResourceBase $resource) {
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
    public function getAccessToken(): string
    {
        // Check for an externally configured access token.
        if ($accessToken = $this->tokenConfig->getAccessToken()) {
            return $accessToken;
        }

        // Get the access token from the session.
        $session = $this->getClient()->getConnector()->getSession();
        $token = (string) $session->get('accessToken');
        $expires = $session->get('expires');

        // If there is no token, or it has expired, make an API request, which
        // automatically obtains a token and saves it to the session.
        if (!$token || $expires < time()) {
            $this->getMyUserId(true);
            if (!$token = (string) $session->get('accessToken')) {
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
    public function urlSort(string $a, string $b): int
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
     * This will throw an exception if the user is not logged in, if there is no login event subscriber registered.
     *
     * @see Api::getExternalHttpClient()
     *
     * @return ClientInterface
     */
    public function getHttpClient(): ClientInterface
    {
        return $this->getClient()->getConnector()->getClient();
    }

    /**
     * Get a new HTTP client instance for external APIs, without Platform.sh authentication.
     *
     * @see Api::getHttpClient()
     *
     * @return ClientInterface
     */
    public function getExternalHttpClient()
    {
        return new Client($this->getGuzzleOptions());
    }

    /**
     * Get the current deployment for an environment.
     *
     * @param Environment $environment
     * @param bool        $refresh
     *
     * @return EnvironmentDeployment
     */
    public function getCurrentDeployment(Environment $environment, bool $refresh = false): EnvironmentDeployment
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
     * Get the default environment in a project.
     *
     * @param Project   $project
     * @param bool|null $refresh
     *
     * @return Environment|null
     */
    public function getDefaultEnvironment(Project $project, bool $refresh = null): ?Environment
    {
        if ($env = $this->getEnvironment($project->default_branch, $project, $refresh)) {
            return $env;
        }
        $envs = $this->getEnvironments($project, $refresh);

        if (isset($envs[$project->default_branch])) {
            return $envs[$project->default_branch];
        }

        // If there is only one environment, use that.
        if (count($envs) <= 1) {
            return \reset($envs) ?: null;
        }

        // Check if there is only one "main" environment.
        $main = \array_filter($envs, function (Environment $environment) {
            return $environment->is_main;
        });
        if (\count($main) === 1) {
            return \reset($main) ?: null;
        }

        // Check if there is only one "main" environment without a parent.
        $mainOrphans = \array_filter($main, function (Environment $environment) {
            return $environment->parent === null && $environment->is_main;
        });
        if (\count($mainOrphans) === 1) {
            return \reset($mainOrphans) ?: null;
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
    public function getSiteUrl(Environment $environment, string $appName, ?EnvironmentDeployment $deployment = null): ?string
    {
        $deployment = $deployment ?: $this->getCurrentDeployment($environment);
        $routes = Route::fromDeploymentApi($deployment->routes);

        // Return the first route that matches this app.
        // The routes will already have been sorted.
        $routes = \array_filter($routes, function (Route $route) use ($appName) {
            return $route->type === 'upstream' && $route->getUpstreamName() === $appName;
        });
        $route = reset($routes);
        if ($route) {
            return $route->url;
        }

        // Fall back to the public-url property.
        if ($environment->hasLink('public-url')) {
            return $environment->getLink('public-url');
        }

        return null;
    }

    /**
     * Get help on how to use API tokens.
     *
     * @param string $tag
     *
     * @return string|null
     */
    public function getApiTokenHelp(string $tag = 'info'): ?string
    {
        if ($this->config->has('service.api_token_help_url')) {
            return "To authenticate non-interactively using an API token, see:\n    <$tag>"
                . $this->config->get('service.api_token_help_url') . "</$tag>";
        }

        return null;
    }

    /**
     * Compares domains as a sorting function. Used to sort region IDs.
     *
     * @param string $regionA
     * @param string $regionB
     *
     * @return int
     */
    public static function compareDomains($regionA, $regionB)
    {
        if (strpos($regionA, '.') && strpos($regionB, '.')) {
            $partsA = explode('.', $regionA, 2);
            $partsB = explode('.', $regionB, 2);
            return (\strnatcasecmp($partsA[1], $partsB[1]) * 10) + \strnatcasecmp($partsA[0], $partsB[0]);
        }
        return \strnatcasecmp($regionA, $regionB);
    }

    /**
     * Checks if a project supports environment types.
     *
     * @todo remove this when environment types are supported everywhere
     *
     * @param Project $project
     *
     * @return bool
     */
    public function supportsEnvironmentTypes(Project $project)
    {
        if ($project->operationAvailable('environment-types')) {
            return true;
        }
        foreach ($this->getEnvironments($project) as $env) {
            return $env->hasProperty('type', false);
        }
        return false;
    }

    /**
     * Loads a subscription through various APIs to see which one wins.
     *
     * @param string $id
     * @param Project|null $project
     * @param bool $forWrite
     *
     * @throws \GuzzleHttp\Exception\RequestException
     *
     * @return false|Subscription
     *   The subscription or false if not found.
     */
    public function loadSubscription($id, Project $project = null, $forWrite = true)
    {
        $organizations_enabled = $this->config->getWithDefault('api.organizations', false);
        if (!$organizations_enabled) {
            // Always load the subscription directly if the Organizations API
            // is not enabled.
            return $this->getClient()->getSubscription($id);
        }

        // Attempt to load the subscription directly.
        // This is possible if the user is on the project's access list, or
        // if the user has access to all subscriptions.
        // However, while this legacy API works for reading, it won't always work for writing.
        if (!$forWrite) {
            try {
                $subscription = $this->getClient()->getSubscription($id);
            } catch (BadResponseException $e) {
                if (!$e->getResponse() || $e->getResponse()->getStatusCode() !== 403) {
                    throw $e;
                }
                $subscription = false;
            }
            if ($subscription) {
                $this->stdErr->writeln('Loaded the subscription directly', OutputInterface::VERBOSITY_DEBUG);
                return $subscription;
            }
        }

        // Use the project's organization, if known.
        $organizationId = null;
        if (isset($project) && $project->hasProperty('organization_id')) {
            $organizationId = $project->getProperty('organization_id', false, false);
        }
        if (empty($organizationId)) {
            foreach ($this->getProjectStubs() as $projectStub) {
                if ($projectStub->subscription_id === $id && (!isset($project) || $project->id === $projectStub->id)) {
                    $organizationId = $projectStub->getProperty('organization_id', false, false);
                    break;
                }
            }
        }
        if (!empty($organizationId) && ($organization = $this->getClient()->getOrganizationById($organizationId))) {
            if ($subscription = $organization->getSubscription($id)) {
                $this->stdErr->writeln("Loaded the subscription via the project's organization: " . $this->getOrganizationLabel($organization), OutputInterface::VERBOSITY_DEBUG);
                return $subscription;
            }
        }

        // Load the user's organizations and try to load the subscription through each one.
        /** @var BadResponseException[] $exceptions */
        $exceptions = [];
        foreach ($this->getClient()->listOrganizationsWithMember($this->getMyUserId()) as $organization) {
            try {
                $subscription = $organization->getSubscription($id);
                if ($subscription) {
                    $this->stdErr->writeln("Loaded the subscription through organization: " . $this->getOrganizationLabel($organization), OutputInterface::VERBOSITY_DEBUG);
                    return $subscription;
                }
            } catch (BadResponseException $e) {
                if (!$e->getResponse() || $e->getResponse()->getStatusCode() !== 403) {
                    throw $e;
                }
                $exceptions[] = $e;
            }
        }
        // Throw a 403 exception if the subscription could not be loaded as a
        // result of permission errors.
        foreach ($exceptions as $exception) {
            throw $exception;
        }
        return false;
    }

    /**
     * Shows information about the currently logged in user and their session, if applicable.
     */
    public function showSessionInfo(bool $newline = true): void
    {
        $sessionId = $this->config->getSessionId();
        if ($sessionId !== 'default' || count($this->listSessionIds()) > 1) {
            if ($newline) {
                $this->stdErr->writeln('');
                $newline = false;
            }
            $this->stdErr->writeln(sprintf('The current session ID is: <info>%s</info>', $sessionId));
            if (!$this->config->isSessionIdFromEnv()) {
                $this->stdErr->writeln(sprintf('Change this using: <info>%s session:switch</info>', $this->config->get('application.executable')));
            }
        }
        if ($this->isLoggedIn()) {
            if ($newline) {
                $this->stdErr->writeln('');
            }
            if ($this->authApiEnabled()) {
                $user = $this->getUser();
                $this->stdErr->writeln(\sprintf(
                    'You are logged in as <info>%s</info> (<info>%s</info>)',
                    $user->username,
                    $user->email
                ));
            } else {
                $accountInfo = $this->getMyAccount();
                $this->stdErr->writeln(\sprintf(
                    'You are logged in as <info>%s</info> (<info>%s</info>)',
                    $accountInfo['username'],
                    $accountInfo['mail']
                ));
            }
        }
    }
}
