<?php

namespace Platformsh\Cli\Service;

use CommerceGuys\Guzzle\Oauth2\AccessToken;
use Composer\CaBundle\CaBundle;
use Doctrine\Common\Cache\CacheProvider;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Message\ResponseInterface;
use Platformsh\Cli\CredentialHelper\Manager;
use Platformsh\Cli\CredentialHelper\SessionStorage;
use Platformsh\Cli\Event\EnvironmentsChangedEvent;
use Platformsh\Cli\GuzzleDebugSubscriber;
use Platformsh\Cli\Model\Route;
use Platformsh\Cli\Util\NestedArrayUtil;
use Platformsh\Cli\Util\Sort;
use Platformsh\Client\Connection\Connector;
use Platformsh\Client\Exception\ApiResponseException;
use Platformsh\Client\Exception\EnvironmentStateException;
use Platformsh\Client\Model\BasicProjectInfo;
use Platformsh\Client\Model\Deployment\EnvironmentDeployment;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\EnvironmentType;
use Platformsh\Client\Model\Organization\Member;
use Platformsh\Client\Model\Organization\Organization;
use Platformsh\Client\Model\Project;
use Platformsh\Client\Model\Ref\UserRef;
use Platformsh\Client\Model\Resource as ApiResource;
use Platformsh\Client\Model\SshKey;
use Platformsh\Client\Model\Subscription;
use Platformsh\Client\Model\Team\TeamMember;
use Platformsh\Client\Model\Team\TeamProjectAccess;
use Platformsh\Client\Model\User;
use Platformsh\Client\PlatformClient;
use Platformsh\Client\Session\Session;
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
    /** @var EventDispatcherInterface */
    public $dispatcher;

    /** @var Config */
    private $config;

    /** @var \Doctrine\Common\Cache\CacheProvider */
    private $cache;

    /** @var OutputInterface */
    private $output;

    /** @var OutputInterface */
    private $stdErr;

    /** @var TokenConfig */
    private $tokenConfig;

    /** @var FileLock */
    private $fileLock;

    /**
     * The library's API client object.
     *
     * This is static so that a freshly logged-in client can then be reused by a parent command with a different service container.
     *
     * @var PlatformClient|null
     */
    private static $client;

    /**
     * A cache of environments lists, keyed by project ID.
     *
     * @var array<string, Environment[]>
     */
    private static $environmentsCache = [];

    /**
     * A cache of environment deployments.
     *
     * @var array<string, EnvironmentDeployment>
     */
    private static $deploymentsCache = [];

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

    /**
     * Constructor.
     *
     * @param Config|null $config
     * @param CacheProvider|null $cache
     * @param OutputInterface|null $output
     * @param TokenConfig|null $tokenConfig
     * @param EventDispatcherInterface|null $dispatcher
     * @param FileLock|null $fileLock
     */
    public function __construct(
        Config $config = null,
        CacheProvider $cache = null,
        OutputInterface $output = null,
        TokenConfig $tokenConfig = null,
        FileLock $fileLock = null,
        EventDispatcherInterface $dispatcher = null
    ) {
        $this->config = $config ?: new Config();
        $this->output = $output ?: new ConsoleOutput();
        $this->stdErr = $this->output instanceof ConsoleOutputInterface ? $this->output->getErrorOutput(): $this->output;
        $this->tokenConfig = $tokenConfig ?: new TokenConfig($this->config);
        $this->fileLock = $fileLock ?: new FileLock($this->config);
        $this->dispatcher = $dispatcher ?: new EventDispatcher();
        $this->cache = $cache ?: CacheFactory::createCacheProvider($this->config);
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

        // Wipe the client so it is re-initialized when needed.
        self::$client = null;
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
        $connectorOptions['api_url'] = $this->config->getApiUrl();
        if ($this->config->has('api.accounts_api_url')) {
            $connectorOptions['accounts'] = $this->config->get('api.accounts_api_url');
        }
        $connectorOptions['certifier_url'] = $this->config->get('api.certifier_url');
        $connectorOptions['verify'] = $this->config->getWithDefault('api.skip_ssl', false) ? false : $this->caBundlePath();

        $connectorOptions['debug'] = false;
        $connectorOptions['client_id'] = $this->config->get('api.oauth2_client_id');
        $connectorOptions['user_agent'] = $this->config->getUserAgent();
        $connectorOptions['timeout'] = $this->config->getWithDefault('api.default_timeout', 30);

        if ($apiToken = $this->tokenConfig->getApiToken()) {
            $connectorOptions['api_token'] = $apiToken;
            $connectorOptions['api_token_type'] = 'exchange';
        } elseif ($accessToken = $this->tokenConfig->getAccessToken()) {
            $connectorOptions['api_token'] = $accessToken;
            $connectorOptions['api_token_type'] = 'access';
        }

        $connectorOptions['proxy'] = $this->guzzleProxyConfig();

        $connectorOptions['token_url'] = $this->config->get('api.oauth2_token_url');
        $connectorOptions['revoke_url'] = $this->config->get('api.oauth2_revoke_url');

        // Acquire a lock to prevent tokens being refreshed at the same time in
        // different CLI processes.
        $refreshLockName = 'refresh--' . $this->config->getSessionIdSlug();
        $connectorOptions['on_refresh_start'] = function ($originalRefreshToken) use ($refreshLockName) {
            $this->debug('Refreshing access token');
            $connector = $this->getClient(false)->getConnector();
            return $this->fileLock->acquireOrWait($refreshLockName, function () {
                $this->stdErr->writeln('Waiting for token refresh lock', OutputInterface::VERBOSITY_VERBOSE);
            }, function () use ($connector, $originalRefreshToken) {
                $session = $connector->getSession();
                $session->load(true);
                $accessToken = $this->tokenFromSession($session);
                return $accessToken && $accessToken->getRefreshToken() !== $originalRefreshToken
                    ? $accessToken : null;
            });
        };
        $connectorOptions['on_refresh_end'] = function () use ($refreshLockName) {
            $this->fileLock->release($refreshLockName);
        };

        $connectorOptions['on_refresh_error'] = function (BadResponseException $e) {
            return $this->onRefreshError($e);
        };

        $connectorOptions['centralized_permissions_enabled'] = $this->config->get('api.centralized_permissions') && $this->config->get('api.organizations');

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
        $path = CaBundle::getSystemCaRootBundlePath();
        $this->debug('Determined CA bundle path: ' . $path);
        return $path;
    }

    /**
     * Logs a debug message.
     *
     * @param string $message
     * @return void
     */
    private function debug($message)
    {
        if ($this->stdErr && $this->stdErr->isDebug()) {
            $this->stdErr->writeln('<options=reverse>DEBUG</> ' . $message);
        }
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

        $reqBody = (string) $e->getRequest()->getBody();
        \parse_str($reqBody, $parsed);
        if (isset($parsed['grant_type']) && $parsed['grant_type'] === 'api_token') {
            $this->stdErr->writeln('<comment>The API token is invalid.</comment>');
        } elseif ($this->isSsoSessionExpired($response)) {
            $this->stdErr->writeln('<comment>Your SSO session has expired. You have been logged out.</comment>');
        } else {
            $this->stdErr->writeln('<comment>Your session has expired. You have been logged out.</comment>');
        }

        if ($response && $this->stdErr->isVeryVerbose()) {
            $this->stdErr->writeln($e->getMessage() . ApiResponseException::getErrorDetails($response));
        }

        $this->stdErr->writeln('');

        $this->dispatcher->dispatch('login_required');
        $session = $this->getClient(false)->getConnector()->getSession();

        return $this->tokenFromSession($session);
    }

    /**
     * Tests if an HTTP response from refreshing a token indicates that the user's SSO session has expired.
     *
     * @param ResponseInterface|null $response
     * @return bool
     */
    private function isSsoSessionExpired(ResponseInterface $response = null)
    {
        if (!$response || $response->getStatusCode() !== 400) {
            return false;
        }
        $respBody = (string) $response->getBody();
        $errDetails = \json_decode($respBody, true);
        return isset($errDetails['error_description'])
            && strpos($errDetails['error_description'], 'SSO session has expired') !== false;
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

        return new AccessToken($tokenData['access_token'], $session->get('tokenType') ?: null, $tokenData);
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
                'debug' => false,
                'verify' => $this->config->getWithDefault('api.skip_ssl', false) ? false : $this->caBundlePath(),
                'proxy' => $this->guzzleProxyConfig(),
                'timeout' => $this->config->getWithDefault('api.default_timeout', 30),
            ],
        ];

        if ($this->output->isVeryVerbose()) {
            $options['defaults']['subscribers'][] = new GuzzleDebugSubscriber($this->output, $this->config->getWithDefault('api.debug', false));
        }

        if (extension_loaded('zlib')) {
            $options['defaults']['decode_content'] = true;
            $options['defaults']['headers']['Accept-Encoding'] = 'gzip';
        }

        return $options;
    }

    /**
     * Returns proxy config in the format expected by Guzzle.
     *
     * @return string[]
     */
    private function guzzleProxyConfig()
    {
        return array_map(function($proxyUrl) {
            // If Guzzle is going to use PHP's built-in HTTP streams,
            // rather than curl, then transform the proxy scheme.
            if (!\extension_loaded('curl') && \ini_get('allow_url_fopen')) {
                return \str_replace(['http://', 'https://'], ['tcp://', 'tcp://'], $proxyUrl);
            }
            return $proxyUrl;
        }, $this->config->getProxies());
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
    public function getClient($autoLogin = true, $reset = false)
    {
        if (!isset(self::$client) || $reset) {
            $options = $this->getConnectorOptions();

            $sessionId = $this->config->getSessionId();

            // Override the session ID if an API token is set.
            // This ensures file storage from other credentials will not be
            // reused.
            if (!empty($options['api_token'])) {
                $sessionId = 'api-token-' . \substr(\hash('sha256', $options['api_token']), 0, 32);
            }

            // Set up a session to store OAuth2 tokens.
            // By default this uses in-memory storage.
            $session = new Session($sessionId);

            // Set up persistent session storage
            // (unless an access token was set directly).
            if (!isset($options['api_token']) || $options['api_token_type'] !== 'access') {
                $this->initSessionStorage();
                // This will load from the session for the first time.
                $session->setStorage($this->sessionStorage);
            }

            $connector = new Connector($options, $session);

            self::$client = new PlatformClient($connector);

            if ($autoLogin && !$connector->isLoggedIn()) {
                $this->dispatcher->dispatch('login_required');
            }

            try {
                $emitter = $connector->getClient()->getEmitter();
                $emitter->on('error', function (ErrorEvent $event) {
                    if ($event->getResponse() && $event->getResponse()->getStatusCode() === 403) {
                        $this->on403($event);
                    }
                });
                if ($this->output->isVeryVerbose()) {
                    $emitter->attach(new GuzzleDebugSubscriber($this->output, $this->config->getWithDefault('api.debug', false)));
                }
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
     * Checks whether a project matches a configured vendor filter.
     *
     * @param string|string[]|null $filters
     * @param BasicProjectInfo $project
     * @return bool
     */
    private function matchesVendorFilter($filters, BasicProjectInfo $project)
    {
        if (empty($filters)) {
            return true;
        }
        if (in_array($project->vendor, (array) $filters)) {
            return true;
        }
        if (empty($project->vendor)) {
            return in_array($this->config->get('service.slug'), (array) $filters);
        }
        return false;
    }

    /**
     * Returns the project list for the current user.
     *
     * @param bool|null $refresh
     *
     * @return BasicProjectInfo[]
     */
    public function getMyProjects($refresh = null)
    {
        $new = $this->config->get('api.centralized_permissions') && $this->config->get('api.organizations');
        $vendorFilter = $this->config->getWithDefault('api.vendor_filter', null);
        $cacheKey = sprintf('%s:my-projects%s:%s', $this->config->getSessionId(), $new ? ':new' : '', is_array($vendorFilter) ? implode(',', $vendorFilter) : (string) $vendorFilter);
        $cached = $this->cache->fetch($cacheKey);

        if ($refresh === false && !$cached) {
            return [];
        } elseif ($refresh || !$cached) {
            $projects = [];
            if ($new) {
                $this->debug('Loading extended access information to fetch the projects list');
                foreach ($this->getClient()->getMyProjects() as $project) {
                    if ($this->matchesVendorFilter($vendorFilter, $project)) {
                        $projects[] = $project;
                    }
                }
            } else {
                $this->debug('Loading account information to fetch the projects list');
                foreach ($this->getClient()->getProjectStubs((bool) $refresh) as $stub) {
                    $project = BasicProjectInfo::fromStub($stub);
                    if ($this->matchesVendorFilter($vendorFilter, $project)) {
                        $projects[] = $project;
                    }
                }
            }
            $this->cache->save($cacheKey, $projects, (int) $this->config->getWithDefault('api.projects_ttl', 600));
        } else {
            $projects = $cached;
            $this->debug('Loaded user project data from cache');
        }

        return $projects;
    }

    /**
     * Return the user's project with the given ID.
     *
     * @param string      $id      The project ID.
     * @param string|null $host The project's hostname. @deprecated no longer used if an api.base_url is configured.
     * @param bool|null   $refresh Whether to bypass the cache.
     *
     * @return Project|false
     */
    public function getProject($id, $host = null, $refresh = null)
    {
        // Ignore the $host if an api.base_url is configured.
        $apiUrl = $this->config->getWithDefault('api.base_url', '');
        if ($apiUrl !== '') {
            $host = null;
        }

        $cacheKey = sprintf('%s:project:%s', $this->config->getSessionId(), $id);
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
                $this->cache->save($cacheKey, $toCache, (int) $this->config->getWithDefault('api.projects_ttl', 600));
            } else {
                return false;
            }
        } else {
            $guzzleClient = $this->getHttpClient();
            $baseUrl = $cached['_endpoint'];
            unset($cached['_endpoint']);
            $project = new Project($cached, $baseUrl, $guzzleClient);
            $this->debug('Loaded project from cache: ' . $id);
        }
        if ($apiUrl !== '') {
            $project->setApiUrl($apiUrl);
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
     * @return array<string, Environment> The user's environments, keyed by ID.
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

            $this->cache->save($cacheKey, $toCache, (int) $this->config->getWithDefault('api.environments_ttl', 120));
        } else {
            $environments = [];
            $endpoint = $project->getUri();
            $guzzleClient = $this->getHttpClient();
            foreach ((array) $cached as $id => $data) {
                $environments[$id] = new Environment($data, $endpoint, $guzzleClient, true);
            }
            $this->debug('Loaded environments from cache');
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
     * Returns the environment types on a project.
     *
     * @param bool|null $refresh Whether to refresh the list of types.
     *
     * @return EnvironmentType[]
     */
    public function getEnvironmentTypes(Project $project, $refresh = null)
    {
        $cacheKey = sprintf('environment-types:%s', $project->id);

        /** @var EnvironmentType[] $types */
        $types = [];

        $cached = $this->cache->fetch($cacheKey);

        if ($refresh === false && !$cached) {
            return [];
        } elseif ($refresh || !$cached) {
            $types = $project->getEnvironmentTypes();
            $cachedTypes = \array_map(function (EnvironmentType $type) {
                return $type->getData() + ['_uri' => $type->getUri()];
            }, $types);
            $this->cache->save($cacheKey, $cachedTypes, (int) $this->config->getWithDefault('api.environments_ttl', 120));
        } else {
            $guzzleClient = $this->getHttpClient();
            foreach ((array) $cached as $data) {
                $types[] = new EnvironmentType($data, $data['_uri'], $guzzleClient);
            }
            $this->debug('Loaded environment types from cache for project: ' . $project->id);
        }

        return $types;
    }

    /**
     * Get the current user's account info.
     *
     * @param bool $reset
     *
     * @return array{
     *     'id': string,
     *     'username': string,
     *     'email': string,
     *     'first_name': string,
     *     'last_name': string,
     *     'display_name': string,
     *     'phone_number_verified': bool,
     * }
     */
    public function getMyAccount($reset = false)
    {
        $user = $this->getUser(null, $reset);
        return $user->getProperties() + [
            'display_name' => trim($user->first_name . ' ' . $user->last_name),
        ];
    }

    /**
     * Get the current user's legacy account info, including SSH keys.
     *
     * @param bool $reset
     *
     * @return array{'id': string, 'username': string, 'mail': string, 'display_name': string, 'ssh_keys': array}
     */
    private function getLegacyAccountInfo($reset = false)
    {
        $cacheKey = sprintf('%s:my-account', $this->config->getSessionId());
        $info = $this->cache->fetch($cacheKey);
        if (!$reset && $info) {
            $this->debug('Loaded account information from cache');
        } else {
            $info = $this->getClient()->getAccountInfo($reset);
            $this->cache->save($cacheKey, $info, (int) $this->config->getWithDefault('api.users_ttl', 600));
        }

        return $info;
    }

    /**
     * Shortcut to return the ID of the current user.
     *
     * @return string|false
     */
    public function getMyUserId($reset = false)
    {
        return $this->getClient()->getMyUserId($reset);
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
        $data = $this->getLegacyAccountInfo($reset);

        return SshKey::wrapCollection($data['ssh_keys'], rtrim($this->config->getApiUrl(), '/') . '/', $this->getHttpClient());
    }

    /**
     * Get user information.
     *
     * This is from the /users API which deals with basic authentication-related data.
     *
     * @param string|null $id
     *   The user ID. Defaults to the current user.
     * @param bool $reset
     *
     * @return User
     */
    public function getUser($id = null, $reset = false)
    {
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
            $this->cache->save($cacheKey, $user->getData(), (int) $this->config->getWithDefault('api.users_ttl', 600));
        } else {
            $connector = $this->getClient()->getConnector();
            $user = new User($data, $connector->getApiUrl() . '/users', $connector->getClient());
            $this->debug('Loaded user info from cache: ' . $id);
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
        $this->cache->delete(sprintf('%s:project-stubs', $this->config->getSessionId()));
        $this->cache->delete(sprintf('%s:my-account', $this->config->getSessionId()));
    }

    /**
     * Sorts API resources, supporting a nested property lookup.
     *
     * Keys will be preserved.
     *
     * @param ApiResource[] &$resources
     * @param string        $propertyPath
     * @param bool          $reverse
     *
     * @return void
     */
    public static function sortResources(array &$resources, $propertyPath, $reverse = false)
    {
        uasort($resources, function (ApiResource $a, ApiResource $b) use ($propertyPath, $reverse) {
            $cmp = Sort::compare(
                static::getNestedProperty($a, $propertyPath, false),
                static::getNestedProperty($b, $propertyPath, false)
            );
            return $reverse ? -$cmp : $cmp;
        });
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
     * Returns a project label.
     *
     * @param Project|BasicProjectInfo|\Platformsh\Client\Model\Organization\Project|TeamProjectAccess|string $project
     * @param string|false $tag
     *
     * @return string
     */
    public function getProjectLabel($project, $tag = 'info')
    {
        static $titleCache = [];
        if ($project instanceof Project || $project instanceof BasicProjectInfo || $project instanceof \Platformsh\Client\Model\Organization\Project) {
            $title = $project->title;
            $id = $project->id;
            $titleCache[$id] = $title;
        } elseif ($project instanceof TeamProjectAccess) {
            $title = $project->project_title;
            $id = $project->project_id;
            $titleCache[$id] = $title;
        } elseif (is_string($project)) {
            if (isset($titleCache[$project])) {
                $title = $titleCache[$project];
                $id = $project;
            } else {
                $projectObj = $this->getProject($project);
                if (!$projectObj) {
                    throw new \InvalidArgumentException('Project not found: ' . $project);
                }
                $title = $projectObj->title;
                $id = $projectObj->id;
                $titleCache[$id] = $title;
            }
        } else {
            throw new \InvalidArgumentException('Invalid type for $project');
        }
        $pattern = strlen($title) > 0 ? '%2$s (%3$s)' : '%3$s';
        if ($tag !== false) {
            $pattern = strlen($title) > 0 ? '<%1$s>%2$s</%1$s> (%3$s)' : '<%1$s>%3$s</%1$s>';
        }

        return sprintf($pattern, $tag, $title, $id);
    }

    /**
     * Returns an environment label.
     *
     * @param Environment  $environment
     * @param string|false $tag
     * @param bool $showType
     *
     * @return string
     */
    public function getEnvironmentLabel(Environment $environment, $tag = 'info', $showType = true)
    {
        $id = $environment->id;
        $title = $environment->title;
        $type = $environment->type;
        $use_title = strlen($title) > 0 && $title !== $id && strtolower($title) !== $id;
        $use_type = $showType && $type !== null && $type !== $id;
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
        // Check for an externally configured access token.
        if ($accessToken = $this->tokenConfig->getAccessToken()) {
            return $accessToken;
        }

        // Get the access token from the session.
        $session = $this->getClient()->getConnector()->getSession();
        $token = $session->get('accessToken');
        $expires = $session->get('expires');

        // If there is no token, or it has expired, make an API request, which
        // automatically obtains a token and saves it to the session.
        if (!$token || $expires < time()) {
            $this->getUser(null, true);
            if (!$token = $session->get('accessToken')) {
                throw new \RuntimeException('No access token found');
            }
        }

        return $token;
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
    public function getHttpClient()
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
     * @param bool        $required
     *
     * @return EnvironmentDeployment|false
     *   The current deployment, or false if $required is false and there is no current deployment.
     */
    public function getCurrentDeployment(Environment $environment, $refresh = false, $required = true)
    {
        $cacheKey = implode(':', ['current-deployment', $environment->project, $environment->id, $environment->head_commit]);
        if (!$refresh && isset(self::$deploymentsCache[$cacheKey])) {
            return self::$deploymentsCache[$cacheKey];
        }
        $data = $this->cache->fetch($cacheKey);
        if ($data === false || $refresh) {
            try {
                $deployment = $environment->getCurrentDeployment($required);
            } catch (EnvironmentStateException $e) {
                if ($e->getEnvironment()->status === 'inactive') {
                    throw new EnvironmentStateException('The environment is inactive', $e->getEnvironment());
                }
                throw $e;
            }
            if (!$required && $deployment === false) {
                return self::$deploymentsCache[$cacheKey] = false;
            }
            $data = $deployment->getData();
            $data['_uri'] = $deployment->getUri();
            $this->cache->save($cacheKey, $data);
        } else {
            $deployment = new EnvironmentDeployment($data, $data['_uri'], $this->getHttpClient(), true);
            $this->debug('Loaded environment deployment from cache for environment: ' . $environment->id);
        }

        return self::$deploymentsCache[$cacheKey] = $deployment;
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

        return isset(self::$deploymentsCache[$cacheKey]) || $this->cache->contains($cacheKey);
    }

    /**
     * Returns a default environment for a project.
     *
     * This may be the one set as the project's default_branch, or another
     * environment, e.g. if the user only has access to 1 environment.
     *
     * @param Environment[] $envs
     * @param Project $project
     * @param bool $onlyDefaultBranch Only use the default_branch.
     *
     * @return Environment|null
     */
    public function getDefaultEnvironment(array $envs, Project $project, $onlyDefaultBranch = false)
    {
        if ($project->default_branch === '') {
            throw new \RuntimeException('Default branch not set');
        }
        foreach ($envs as $env) {
            if ($env->id === $project->default_branch) {
                return $env;
            }
        }
        if ($onlyDefaultBranch) {
            return null;
        }

        // If there is only one environment, use that.
        if (count($envs) <= 1) {
            return \reset($envs) ?: null;
        }

        // Check if there is only one "production" environment.
        $prod = \array_filter($envs, function (Environment $environment) {
            return $environment->type === 'production';
        });
        if (\count($prod) === 1) {
            return \reset($prod);
        }

        // Check if there is only one "main" environment.
        $main = \array_filter($envs, function (Environment $environment) {
            return $environment->is_main;
        });
        if (\count($main) === 1) {
            return \reset($main);
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
                $this->debug('Loaded the subscription directly');
                return $subscription;
            }
        }

        // Use the project's organization, if known.
        $organizationId = null;
        if (isset($project) && $project->hasProperty('organization_id')) {
            $organizationId = $project->getProperty('organization_id', false, false);
        }
        if (empty($organizationId)) {
            foreach ($this->getMyProjects() as $info) {
                if ($info->subscription_id === $id && (!isset($project) || $project->id === $info->id)) {
                    $organizationId = !empty($info->organization_ref->id) ? $info->organization_ref->id : false;
                    break;
                }
            }
        }
        if (!empty($organizationId) && ($organization = $this->getClient()->getOrganizationById($organizationId))) {
            if ($subscription = $organization->getSubscription($id)) {
                $this->debug("Loaded the subscription via the project's organization: " . $this->getOrganizationLabel($organization));
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
                    $this->debug("Loaded the subscription through organization: " . $this->getOrganizationLabel($organization));
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
     * Returns whether the user is required to verify their phone number before certain actions.
     *
     * @return array{'state': bool, 'type': string}
     */
    public function checkUserVerification()
    {
        if (!$this->config->getWithDefault('api.user_verification', false)) {
            return ['state' => false, 'type' => ''];
        }

        // Check the API to see if verification is required.
        return $this->getHttpClient()->post( '/me/verification')->json();
    }

    /**
     * Returns a descriptive label for a referenced user.
     *
     * @param UserRef $userRef
     * @param string|false $tag
     *
     * @return string
     */
    public function getUserRefLabel(UserRef $userRef, $tag = 'info')
    {
        $name = trim($userRef->first_name . ' ' . $userRef->last_name);
        $pattern = $name !== '' ? '%2$s \<%3$s>' : '%3$s';
        if ($tag !== false) {
            $pattern = $name !== '' ? '<%1$s>%2$s</%1$s> \<%3$s>' : '<%1$s>%3$s</%1$s>';
        }
        return \sprintf($pattern, $tag, $name, $userRef->email);
    }

    /**
     * Loads an organization by ID, with caching.
     *
     * @param string $id
     * @param bool $reset
     * @return Organization|false
     */
    public function getOrganizationById($id, $reset = false)
    {
        $cacheKey = 'organization:' . $id;
        if (!$reset && ($cached = $this->cache->fetch($cacheKey))) {
            $this->debug('Loaded organization from cache: ' . $id);
            return new Organization($cached, $cached['_url'], $this->getHttpClient());
        }
        $organization = $this->getClient()->getOrganizationById($id);
        if ($organization) {
            $data = $organization->getData();
            $data['_url'] = $organization->getUri();
            $this->cache->save($cacheKey, $data, $this->config->getWithDefault('api.orgs_ttl', 3600));
        }
        return $organization;
    }

    /**
     * Returns the Console URL for a project, with caching.
     *
     * @param Project $project
     * @param bool $reset
     *
     * @return false|string
     */
    public function getConsoleURL(Project $project, $reset = false)
    {
        if ($this->config->has('service.console_url') && $this->config->get('api.organizations')) {
            // Load the organization name if possible.
            $firstSegment = $organizationId = $project->getProperty('organization');
            try {
                $organization = $this->getOrganizationById($organizationId, $reset);
                if ($organization) {
                    $firstSegment = $organization->name;
                }
            } catch (BadResponseException $e) {
                if ($e->getResponse() && $e->getResponse()->getStatusCode() === 403) {
                    trigger_error($e->getMessage(), E_USER_WARNING);
                } else {
                    throw $e;
                }
            }

            return ltrim($this->config->get('service.console_url'), '/') . '/' . rawurlencode($firstSegment) . '/' . rawurlencode($project->id);
        }
        $subscription = $this->loadSubscription($project->getSubscriptionId(), $project);
        return $subscription ? $subscription->project_ui : false;
    }

    /**
     * Loads an organization member by email, by paging through all the members in the organization.
     *
     * @TODO replace this with a more efficient API when available
     *
     * @param Organization $organization
     * @param string $email
     * @return Member|null
     */
    public function loadMemberByEmail(Organization $organization, $email)
    {
        foreach ($this->listMembers($organization) as $member) {
            if ($member->getUserInfo() && strcasecmp($member->getUserInfo()->email, $email) === 0) {
                return $member;
            }
        }
        return null;
    }

    /**
     * Loads organization members (with static caching).
     *
     * @param bool $reset
     * @return Member[]
     */
    public function listMembers(Organization $organization, $reset = false)
    {
        static $cache = [];
        $cacheKey = $organization->id;
        if (!$reset && isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }
        /** @var Member[] $members */
        $members = [];
        $httpClient = $this->getHttpClient();
        $url = $organization->getLink('members');
        while ($url) {
            $result = Member::getCollectionWithParent($url, $httpClient);
            $members = array_merge($members, $result['items']);
            $url = $result['collection']->getNextPageUrl();
        }
        return $cache[$cacheKey] = $members;
    }

    /**
     * Returns a label for an organization or team member.
     *
     * @param Member|TeamMember $member
     * @return string
     */
    public function getMemberLabel($member)
    {
        if ($userInfo = $member->getUserInfo()) {
            $label = sprintf('%s (%s)', trim($userInfo->first_name . ' ' . $userInfo->last_name), $userInfo->email);
        } else {
            $label = $member->user_id;
        }
        return $label;
    }

    /**
     * Checks if a project supports the Flexible Resources API, AKA Sizing API.
     *
     * @param Project $project
     * @param EnvironmentDeployment|null $deployment
     * @return bool
     */
    public function supportsSizingApi(Project $project, EnvironmentDeployment $deployment = null)
    {
        if (isset($deployment->project_info['settings'])) {
            return !empty($deployment->project_info['settings']['sizing_api_enabled']);
        }
        $cacheKey = 'project-settings:' . $project->id;
        $cachedSettings = $this->cache->fetch($cacheKey);
        if (!empty($cachedSettings['sizing_api_enabled'])) {
            return true;
        }
        $settings = $this->getHttpClient()->get($project->getUri() . '/settings')->json();
        $this->cache->save($cacheKey, $settings, $this->config->get('api.projects_ttl'));
        return !empty($settings['sizing_api_enabled']);
    }
}
