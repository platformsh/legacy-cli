<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Client\Model\Deployment\Service;
use Platformsh\Client\Model\Deployment\WebApp;
use Platformsh\Client\Model\Deployment\Worker;
use Platformsh\Client\Model\Project\Capabilities;
use GuzzleHttp\HandlerStack;
use Symfony\Component\Filesystem\Filesystem;
use GuzzleHttp\Exception\RequestException;
use Platformsh\Client\Model\Integration;
use Composer\CaBundle\CaBundle;
use Doctrine\Common\Cache\CacheProvider;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Utils;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use Platformsh\Cli\CredentialHelper\KeyringUnavailableException;
use Platformsh\Cli\CredentialHelper\Manager;
use Platformsh\Cli\CredentialHelper\SessionStorage as CredentialHelperStorage;
use Platformsh\Cli\Event\EnvironmentsChangedEvent;
use Platformsh\Cli\Event\LoginRequiredEvent;
use Platformsh\Cli\Exception\ProcessFailedException;
use Platformsh\Cli\GuzzleDebugMiddleware;
use Platformsh\Cli\Model\Route;
use Platformsh\Cli\Util\NestedArrayUtil;
use Platformsh\Cli\Util\Sort;
use Platformsh\Client\Connection\Connector;
use Platformsh\Client\Exception\ApiResponseException;
use Platformsh\Client\Exception\EnvironmentStateException;
use Platformsh\Client\Model\AutoscalingSettings;
use Platformsh\Client\Model\BasicProjectInfo;
use Platformsh\Client\Model\Deployment\EnvironmentDeployment;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\EnvironmentType;
use Platformsh\Client\Model\Organization\Member;
use Platformsh\Client\Model\Organization\Organization;
use Platformsh\Client\Model\Project;
use Platformsh\Client\Model\Ref\UserRef;
use Platformsh\Client\Model\ApiResourceBase as ApiResource;
use Platformsh\Client\Model\SshKey;
use Platformsh\Client\Model\Subscription;
use Platformsh\Client\Model\Team\TeamMember;
use Platformsh\Client\Model\Team\TeamProjectAccess;
use Platformsh\Client\Model\User;
use Platformsh\Client\PlatformClient;
use Platformsh\Client\Session\Session;
use Platformsh\Client\Session\SessionInterface;
use Platformsh\Client\Session\Storage\File;
use Platformsh\Client\Session\Storage\SessionStorageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Decorates the PlatformClient API client to provide aggressive caching.
 */
class Api
{
    private static bool $printedApiTokenWarning = false;

    private readonly EventDispatcherInterface $dispatcher;
    private readonly Config $config;
    private readonly CacheProvider $cache;
    private readonly OutputInterface $output;
    private readonly OutputInterface $stdErr;
    private readonly TokenConfig $tokenConfig;
    private readonly FileLock $fileLock;
    private readonly Io $io;

    /**
     * The library's API client object.
     *
     * This is static so that a freshly logged-in client can then be reused by a parent command with a different service container.
     */
    private static ?PlatformClient $client = null;

    /**
     * A cache of environments lists, keyed by project ID.
     *
     * @var array<string, Environment[]>
     */
    private static array $environmentsCache = [];

    /**
     * A cache of environment deployments.
     *
     * @var array<string, EnvironmentDeployment>
     */
    private static array $deploymentsCache = [];

    /**
     * A cache of not-found environment IDs.
     *
     * @var array<string, true>
     *
     * @see Api::getEnvironment()
     */
    private static array $notFound = [];

    /**
     * Session storage, via files or credential helpers.
     *
     * @see Api::initSessionStorage()
     */
    private ?SessionStorageInterface $sessionStorage = null;

    /**
     * Sets whether we are currently verifying login using a test request.
     */
    public bool $inLoginCheck = false;

    /**
     * Constructor.
     *
     * @param Config|null $config
     * @param CacheProvider|null $cache
     * @param OutputInterface|null $output
     * @param TokenConfig|null $tokenConfig
     * @param EventDispatcherInterface|null $dispatcher
     * @param FileLock|null $fileLock
     * @param Io|null $io
     */
    public function __construct(
        ?Config                   $config = null,
        ?CacheProvider            $cache = null,
        ?OutputInterface          $output = null,
        ?Io                       $io = null,
        ?TokenConfig              $tokenConfig = null,
        ?FileLock                 $fileLock = null,
        ?EventDispatcherInterface $dispatcher = null,
    ) {
        $this->config = $config ?: new Config();
        $this->output = $output ?: new ConsoleOutput();
        $this->stdErr = $this->output instanceof ConsoleOutputInterface ? $this->output->getErrorOutput() : $this->output;
        $this->io = $io ?: new Io($this->output);
        $this->tokenConfig = $tokenConfig ?: new TokenConfig($this->config);
        $this->fileLock = $fileLock ?: new FileLock($this->config);
        $this->dispatcher = $dispatcher ?: new EventDispatcher();
        $this->cache = $cache ?: CacheFactory::createCacheProvider($this->config);
    }

    /**
     * Sets up listeners (called by the DI container).
     *
     * @param AutoLoginListener $autoLoginListener
     * @param DrushAliasUpdater $drushAliasUpdater
     */
    #[Required]
    public function injectListeners(
        AutoLoginListener $autoLoginListener,
        DrushAliasUpdater $drushAliasUpdater,
    ): void {
        $this->dispatcher->addListener(
            'login.required',
            $autoLoginListener->onLoginRequired(...),
        );
        $this->dispatcher->addListener(
            'environments.changed',
            $drushAliasUpdater->onEnvironmentsChanged(...),
        );
    }

    /**
     * Returns whether the CLI is authenticating using an API token.
     *
     * @param bool $includeStored
     *
     * @return bool
     */
    public function hasApiToken(bool $includeStored = true): bool
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
    public function listSessionIds(): array
    {
        $ids = [];
        if ($this->sessionStorage instanceof CredentialHelperStorage) {
            $ids = $this->sessionStorage->listSessionIds();
        }
        $dir = $this->config->getSessionDir();
        $files = glob($dir . '/sess-cli-*', GLOB_NOSORT);
        if ($files !== false) {
            foreach ($files as $file) {
                if (\preg_match('@/sess-cli-([a-z0-9_-]+)@i', $file, $matches)) {
                    $ids[] = $matches[1];
                }
            }
        }
        $ids = \array_filter($ids, fn($id): bool => !str_starts_with((string) $id, 'api-token-'));

        return \array_unique($ids);
    }

    /**
     * Checks if any sessions exist (with any session ID).
     *
     * @return bool
     */
    public function anySessionsExist(): bool
    {
        if ($this->sessionStorage instanceof CredentialHelperStorage && $this->sessionStorage->hasAnySessions()) {
            return true;
        }
        $dir = $this->config->getSessionDir();
        $files = glob($dir . '/sess-cli-*/*', GLOB_NOSORT);

        return !empty($files);
    }

    /**
     * Logs out of the current session.
     */
    public function logout(): void
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
            (new Filesystem())->remove($dir);
        }

        // Wipe the client so it is re-initialized when needed.
        self::$client = null;
    }

    /**
     * Deletes all sessions.
     */
    public function deleteAllSessions(): void
    {
        if ($this->sessionStorage instanceof CredentialHelperStorage) {
            $this->sessionStorage->deleteAll();
        }
        $dir = $this->config->getSessionDir();
        if (is_dir($dir)) {
            (new Filesystem())->remove($dir);
        }
    }

    /**
     * Returns options to instantiate an API client library Connector.
     *
     * @see Connector::__construct()
     *
     * @return array<string, mixed>
     */
    private function getConnectorOptions(): array
    {
        $connectorOptions = [];
        $connectorOptions['api_url'] = $this->config->getApiUrl();
        if ($this->config->has('api.accounts_api_url')) {
            $connectorOptions['accounts'] = $this->config->get('api.accounts_api_url');
        }
        $connectorOptions['certifier_url'] = $this->config->get('api.certifier_url');
        $connectorOptions['verify'] = $this->config->getBool('api.skip_ssl') ? false : $this->caBundlePath();

        $connectorOptions['debug'] = false;
        $connectorOptions['client_id'] = $this->config->get('api.oauth2_client_id');
        $connectorOptions['user_agent'] = $this->config->getUserAgent();
        $connectorOptions['timeout'] = $this->config->getInt('api.default_timeout');

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
            $this->io->debug('Refreshing access token');
            $connector = $this->getClient(false)->getConnector();
            return $this->fileLock->acquireOrWait($refreshLockName, function (): void {
                $this->stdErr->writeln('Waiting for token refresh lock', OutputInterface::VERBOSITY_VERBOSE);
            }, function () use ($connector, $originalRefreshToken) {
                $session = $connector->getSession();
                $accessToken = $this->tokenFromSession($session);
                return $accessToken && $accessToken->getRefreshToken() !== $originalRefreshToken
                    ? $accessToken : null;
            });
        };
        $connectorOptions['on_refresh_end'] = function () use ($refreshLockName): void {
            $this->fileLock->release($refreshLockName);
        };

        $connectorOptions['on_refresh_error'] = fn(IdentityProviderException $e): ?AccessToken => $this->onRefreshError($e);

        $connectorOptions['on_step_up_auth_response'] = fn(ResponseInterface $response) => $this->onStepUpAuthResponse($response);

        $connectorOptions['centralized_permissions_enabled'] = $this->config->getBool('api.centralized_permissions') && $this->config->getBool('api.organizations');

        // Add middlewares.
        $connectorOptions['middlewares'] = [];
        // Debug responses.
        $connectorOptions['middlewares'][] = new GuzzleDebugMiddleware($this->output, $this->config->getBool('api.debug'));
        // Handle 403 errors.
        $connectorOptions['middlewares'][] = fn(callable $handler): \Closure => fn(RequestInterface $request, array $options) => $handler($request, $options)->then(function (ResponseInterface $response) use ($request): ResponseInterface {
            if ($response->getStatusCode() === 403) {
                $this->on403($request);
            }
            return $response;
        });

        return $connectorOptions;
    }

    /**
     * Returns the path to the CA bundle or file detected by Composer.
     *
     * If a system CA bundle cannot be detected, Composer will use its bundled
     * file. It will copy the file to a temporary directory if necessary (when
     * running inside a Phar).
     *
     * @return string
     */
    private function caBundlePath(): string
    {
        static $path;
        if ($path === null) {
            $path = CaBundle::getSystemCaRootBundlePath();
            $this->io->debug('Determined CA bundle path: ' . $path);
        }
        return $path;
    }

    private function onStepUpAuthResponse(ResponseInterface $response): ?AccessToken
    {
        if ($this->inLoginCheck) {
            return null;
        }

        $this->io->debug(ApiResponseException::getErrorDetails($response));

        $session = $this->getClient(false)->getConnector()->getSession();
        $previousAccessToken = $session->get('accessToken');

        $body = (array) Utils::jsonDecode((string) $response->getBody(), true);
        $authMethods = $body['amr'] ?? [];
        $maxAge = $body['max_age'] ?? null;

        $this->dispatcher->dispatch(new LoginRequiredEvent($authMethods, $maxAge, $this->hasApiToken()), 'login.required');

        $this->stdErr->writeln('');
        $session = $this->getClient(false)->getConnector()->getSession();
        $newAccessToken = $this->tokenFromSession($session);
        if ($newAccessToken && $newAccessToken->getToken() !== $previousAccessToken) {
            return $newAccessToken;
        }

        return null;
    }

    /**
     * Logs out and prompts for re-authentication after a token refresh error.
     *
     * @param IdentityProviderException $e
     *
     * @return AccessToken|null
     */
    private function onRefreshError(IdentityProviderException $e): ?AccessToken
    {
        if ($this->inLoginCheck) {
            return null;
        }
        $data = $e->getResponseBody();
        if (!is_array($data) || !isset($data['error'])) {
            return null;
        }

        $this->io->debug($e->getMessage());

        $this->logout();

        if ($this->isSsoSessionExpired($data)) {
            $this->stdErr->writeln('<comment>Your SSO session has expired. You have been logged out.</comment>');
        } elseif ($this->isApiTokenInvalid($data)) {
            $this->stdErr->writeln('<comment>The API token is invalid.</comment>');
        } else {
            $this->stdErr->writeln('<comment>Your session has expired. You have been logged out.</comment>');
        }

        $this->stdErr->writeln('');

        $this->dispatcher->dispatch(new LoginRequiredEvent([], null, $this->hasApiToken()), 'login.required');
        $session = $this->getClient(false)->getConnector()->getSession();

        return $this->tokenFromSession($session);
    }

    /**
     * Tests if an HTTP response from refreshing a token indicates that the user's SSO session has expired.
     *
     * @param array<string, mixed> $data
     */
    private function isSsoSessionExpired(array $data): bool
    {
        if (isset($data['error']) && $data['error'] === 'invalid_grant') {
            return isset($data['error_description'])
                && str_contains((string) $data['error_description'], 'SSO session has expired');
        }
        return false;
    }

    /**
     * Tests if an error from refreshing a token indicates that the user's API token is invalid.
     *
     * @param array<string, mixed> $body
     */
    private function isApiTokenInvalid(mixed $body): bool
    {
        if (is_array($body) && isset($body['error']) && $body['error'] === 'invalid_grant') {
            return isset($body['error_description'])
                && str_contains((string) $body['error_description'], 'API token');
        }
        return false;
    }

    /**
     * Loads and returns an AccessToken, if possible, from a session.
     *
     * @param SessionInterface $session
     *
     * @return AccessToken|null
     */
    private function tokenFromSession(SessionInterface $session): ?AccessToken
    {
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
     * Returns proxy config in the format expected by Guzzle.
     *
     * @return string[]
     */
    private function guzzleProxyConfig(): array
    {
        return array_map(function ($proxyUrl) {
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
    public function getClient(bool $autoLogin = true, bool $reset = false): PlatformClient
    {
        if (!isset(self::$client) || $reset) {
            $options = $this->getConnectorOptions();

            $sessionId = $this->config->getSessionId();

            // Override the session ID if an API token is set.
            // This ensures file storage from other credentials will not be
            // reused.
            if (!empty($options['api_token'])) {
                $sessionId = 'api-token-' . \substr(\hash('sha256', (string) $options['api_token']), 0, 32);
            }

            // Set up a session to store OAuth2 tokens.
            // By default this uses in-memory storage.
            $session = new Session($sessionId);

            // Set up persistent session storage
            // (unless an access token was set directly).
            if (!isset($options['api_token']) || $options['api_token_type'] !== 'access') {
                $this->initSessionStorage();
                $this->io->debug('Loading session');
                try {
                    $session->setStorage($this->sessionStorage);
                } catch (\RuntimeException $e) {
                    if ($this->sessionStorage instanceof CredentialHelperStorage) {
                        $previous = $e->getPrevious();
                        if ($previous instanceof ProcessTimedOutException) {
                            throw KeyringUnavailableException::fromTimeout($previous);
                        } elseif ($previous instanceof ProcessFailedException) {
                            throw KeyringUnavailableException::fromFailure($previous);
                        }
                    }
                    throw $e;
                }
            }

            $connector = new Connector($options, $session);

            self::$client = new PlatformClient($connector);

            if (!self::$printedApiTokenWarning && $this->onContainer() && (getenv($this->config->getStr('application.env_prefix') . 'TOKEN') || $this->hasApiToken(false))) {
                $this->stdErr->writeln('<fg=yellow;options=bold>Warning:</>');
                $this->stdErr->writeln('<fg=yellow>An API token is set. Anyone with SSH access to this environment can read the token.</>');
                $this->stdErr->writeln('<fg=yellow>Please ensure the token only has strictly necessary access.</>');
                $this->stdErr->writeln('');
                self::$printedApiTokenWarning = true;
            }

            if ($autoLogin && !$connector->isLoggedIn()) {
                $this->dispatcher->dispatch(new LoginRequiredEvent([], null, $this->hasApiToken()), 'login.required');
            }
        }

        return self::$client;
    }

    /**
     * Detects if running on an application container.
     *
     * @return bool
     */
    private function onContainer(): bool
    {
        $envPrefix = $this->config->getStr('service.env_prefix');
        return getenv($envPrefix . 'PROJECT') !== false
            && getenv($envPrefix . 'BRANCH') !== false
            && getenv($envPrefix . 'TREE_ID') !== false;
    }

    /**
     * Initializes session credential storage.
     */
    private function initSessionStorage(): void
    {
        if (!isset($this->sessionStorage)) {
            // Attempt to use the docker-credential-helpers.
            $manager = new Manager($this->config);
            if ($manager->isSupported()) {
                if ($manager->isInstalled()) {
                    $this->io->debug('Using Docker credential helper for session storage');
                } else {
                    $this->io->debug('Installing Docker credential helper for session storage');
                    $manager->install();
                }
                $this->sessionStorage = new CredentialHelperStorage($manager, $this->config->getStr('application.slug'));
                return;
            }

            // Fall back to file storage.
            $this->io->debug('Using filesystem for session storage');
            $this->sessionStorage = new File($this->config->getSessionDir());
        }
    }

    /**
     * Constructs a stream context for using the API with stream functions.
     *
     * @param int|float $timeout
     *
     * @return resource
     */
    public function getStreamContext(int|float $timeout = 15)
    {
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
    private function matchesVendorFilter(string|array|null $filters, BasicProjectInfo $project): bool
    {
        if (empty($filters)) {
            return true;
        }
        if (in_array($project->vendor, (array) $filters)) {
            return true;
        }
        // Show projects with the "upsun" vendor under the "platformsh" filter.
        if ($project->vendor === 'upsun' && in_array('platformsh', (array) $filters)) {
            return true;
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
    public function getMyProjects(?bool $refresh = null): array
    {
        $new = $this->config->getBool('api.centralized_permissions') && $this->config->getBool('api.organizations');
        /** @var string[]|string|null $vendorFilter */
        $vendorFilter = $this->config->getWithDefault('api.vendor_filter', null);
        $cacheKey = $this->myProjectsCacheKey();
        $cached = $this->cache->fetch($cacheKey);

        if ($refresh === false && !$cached) {
            return [];
        } elseif ($refresh || !$cached) {
            $projects = [];
            if ($new) {
                $this->io->debug('Loading extended access information to fetch the projects list');
                foreach ($this->getClient()->getMyProjects() as $project) {
                    if ($this->matchesVendorFilter($vendorFilter, $project)) {
                        $projects[] = $project;
                    }
                }
            } else {
                $this->io->debug('Loading account information to fetch the projects list');
                foreach ($this->getClient()->getProjectStubs((bool) $refresh) as $stub) {
                    $project = BasicProjectInfo::fromStub($stub);
                    if ($this->matchesVendorFilter($vendorFilter, $project)) {
                        $projects[] = $project;
                    }
                }
            }
            $this->cache->save($cacheKey, $projects, $this->config->getInt('api.projects_ttl'));
        } else {
            $projects = $cached;
            $this->io->debug('Loaded user project data from cache');
        }

        return $projects;
    }

    /**
     * Return the user's project with the given ID.
     *
     * @param string $id The project ID.
     * @param string|null $host The project's hostname. @deprecated no longer used if an api.base_url is configured.
     * @param bool|null $refresh Whether to bypass the cache.
     *
     * @return Project|false
     */
    public function getProject(string $id, ?string $host = null, ?bool $refresh = null): Project|false
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
                $this->cache->save($cacheKey, $toCache, $this->config->getInt('api.projects_ttl'));
            } else {
                return false;
            }
        } else {
            $guzzleClient = $this->getHttpClient();
            $baseUrl = $cached['_endpoint'];
            unset($cached['_endpoint']);
            $project = new Project($cached, $baseUrl, $guzzleClient);
            $this->io->debug('Loaded project from cache: ' . $id);
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
     * @return array<string, Environment> The project's environments, keyed by ID.
     */
    public function getEnvironments(Project $project, ?bool $refresh = null, bool $events = true): array
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
            // Fetch environments with double the default timeout.
            $list = Environment::getCollection($project->getLink('environments'), 0, [
                'timeout' => 2 * $this->config->getInt('api.default_timeout'),
            ], $this->getHttpClient());
            $environments = [];
            $toCache = [];
            foreach ($list as $environment) {
                // Key the list by ID.
                $environments[$environment->id] = $environment;
                $toCache[$environment->id] = $environment->getData();
            }

            // Dispatch an event if the list of environments has changed.
            if ($events && (!$cached || array_diff_key($environments, $cached))) {
                $this->dispatcher->dispatch(
                    new EnvironmentsChangedEvent($project, $environments),
                    'environments_changed',
                );
            }

            $this->cache->save($cacheKey, $toCache, $this->config->getInt('api.environments_ttl'));
        } else {
            $environments = [];
            $endpoint = $project->getUri();
            $guzzleClient = $this->getHttpClient();
            foreach ((array) $cached as $id => $data) {
                $environments[$id] = new Environment($data, $endpoint, $guzzleClient, true);
            }
            $this->io->debug('Loaded environments from cache');
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
    public function getEnvironment(string $id, Project $project, ?bool $refresh = null, bool $tryMachineName = false): Environment|false
    {
        // Statically cache not found environments.
        $cacheKey = $project->id . ':' . $id . ($tryMachineName ? ':mn' : '');
        if (!$refresh && isset(self::$notFound[$cacheKey])) {
            return false;
        }

        $environmentsRefreshed = $refresh === true || ($refresh === null && empty(self::$environmentsCache[$project->id]) && !$this->cache->fetch('environments:' . $project->id));
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
        if (!$environmentsRefreshed) {
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
    public function getEnvironmentTypes(Project $project, ?bool $refresh = null): array
    {
        $cacheKey = sprintf('environment-types:%s', $project->id);

        /** @var EnvironmentType[] $types */
        $types = [];

        $cached = $this->cache->fetch($cacheKey);

        if ($refresh === false && !$cached) {
            return [];
        } elseif ($refresh || !$cached) {
            $types = $project->getEnvironmentTypes();
            $cachedTypes = \array_map(fn(EnvironmentType $type) => $type->getData() + ['_uri' => $type->getUri()], $types);
            $this->cache->save($cacheKey, $cachedTypes, $this->config->getInt('api.environments_ttl'));
        } else {
            $guzzleClient = $this->getHttpClient();
            foreach ((array) $cached as $data) {
                $types[] = new EnvironmentType($data, $data['_uri'], $guzzleClient);
            }
            $this->io->debug('Loaded environment types from cache for project: ' . $project->id);
        }

        return $types;
    }

    /**
     * Get the current user's account info.
     *
     * @param bool $reset
     *
     * @return array{
     *     id: string,
     *     username: string,
     *     email: string,
     *     first_name: string,
     *     last_name: string,
     *     display_name: string,
     *     phone_number_verified: bool,
     * }
     */
    public function getMyAccount(bool $reset = false): array
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
     * @return array{'id': string, 'username': string, 'mail': string, 'display_name': string, 'ssh_keys': array<string, mixed>}
     */
    private function getLegacyAccountInfo(bool $reset = false): array
    {
        $cacheKey = sprintf('%s:my-account', $this->config->getSessionId());
        $info = $this->cache->fetch($cacheKey);
        if (!$reset && $info) {
            $this->io->debug('Loaded account information from cache');
        } else {
            $info = $this->getClient()->getAccountInfo($reset);
            $this->cache->save($cacheKey, $info, $this->config->getInt('api.users_ttl'));
        }

        return $info;
    }

    /**
     * Shortcut to return the ID of the current user.
     */
    public function getMyUserId(bool $reset = false): string
    {
        $id = $this->getClient()->getMyUserId($reset);
        if (!$id) {
            throw new \RuntimeException('No user ID found for the current session.');
        }
        return $id;
    }

    /**
     * Get the logged-in user's SSH keys.
     *
     * @param bool $reset
     *
     * @return SshKey[]
     */
    public function getSshKeys(bool $reset = false): array
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
    public function getUser(?string $id = null, bool $reset = false): User
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
            $this->cache->save($cacheKey, $user->getData(), $this->config->getInt('api.users_ttl'));
        } else {
            $this->io->debug('Loaded user info from cache: ' . $id);
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
            if (str_starts_with($key, $projectId . ':')) {
                unset(self::$notFound[$key]);
            }
        }
    }

    /**
     * Calculates a cache key for the projects list.
     *
     * @return string
     */
    private function myProjectsCacheKey(): string
    {
        $new = $this->config->getBool('api.centralized_permissions') && $this->config->getBool('api.organizations');
        /** @var string[]|string|null $vendorFilter */
        $vendorFilter = $this->config->getWithDefault('api.vendor_filter', null);
        return sprintf('%s:my-projects%s:%s', $this->config->getSessionId(), $new ? ':new' : '', is_array($vendorFilter) ? implode(',', $vendorFilter) : (string) $vendorFilter);
    }

    /**
     * Clears the projects cache.
     */
    public function clearProjectsCache(): void
    {
        $this->cache->delete($this->myProjectsCacheKey());
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
    public static function sortResources(array &$resources, string $propertyPath, bool $reverse = false): void
    {
        uasort($resources, fn(ApiResource $a, ApiResource $b) => Sort::compare(
            static::getNestedProperty($a, $propertyPath, false),
            static::getNestedProperty($b, $propertyPath, false),
            $reverse,
        ));
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
    public static function getNestedProperty(ApiResource $resource, string $propertyPath, bool $lazyLoad = true): mixed
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
                $propertyName,
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
     * Returns a project label.
     *
     * @param Project|BasicProjectInfo|\Platformsh\Client\Model\Organization\Project|TeamProjectAccess|string $project
     * @param string|false $tag
     *
     * @return string
     */
    public function getProjectLabel(
        Project|BasicProjectInfo|\Platformsh\Client\Model\Organization\Project|TeamProjectAccess|string $project,
        string|false $tag = 'info',
    ): string {
        static $titleCache = [];
        if ($project instanceof Project || $project instanceof BasicProjectInfo || $project instanceof \Platformsh\Client\Model\Organization\Project) {
            $title = $project->title;
            $id = $project->id;
            $titleCache[$id] = $title;
        } elseif ($project instanceof TeamProjectAccess) {
            $title = $project->project_title;
            $id = $project->project_id;
            $titleCache[$id] = $title;
        } else {
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
        }
        $pattern = strlen($title) > 0 ? '%2$s (%3$s)' : '%3$s';
        if ($tag !== false) {
            $pattern = strlen($title) > 0 ? '<%1$s>%2$s</%1$s> (%3$s)' : '<%1$s>%3$s</%1$s>';
        }

        return sprintf($pattern, $tag, $title, $id);
    }

    /**
     * Returns an environment label.
     */
    public function getEnvironmentLabel(Environment $environment, string|false $tag = 'info', bool $showType = true): string
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
     */
    public function getOrganizationLabel(Organization $organization, string|false $tag = 'info'): string
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
    public function matchPartialId(string $id, array $resources, string $name = 'Resource'): ApiResource
    {
        $matched = array_filter($resources, fn(ApiResource $resource): bool => str_starts_with((string) $resource->getProperty('id'), $id));

        if (count($matched) > 1) {
            $matchedIds = array_map(fn(ApiResource $resource): mixed => $resource->getProperty('id'), $matched);
            throw new \InvalidArgumentException(sprintf(
                'The partial ID "<error>%s</error>" is ambiguous; it matches the following %s IDs: %s',
                $id,
                strtolower($name),
                "\n  " . implode("\n  ", $matchedIds),
            ));
        } elseif (count($matched) === 0) {
            throw new \InvalidArgumentException(sprintf('%s not found: "<error>%s</error>"', $name, $id));
        }

        return reset($matched);
    }

    /**
     * Returns the OAuth 2 access token.
     *
     * @param bool $forceNew
     *
     * @return string
     */
    public function getAccessToken(bool $forceNew = false): string
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
        if (!$token || $expires < time() || $forceNew) {
            $this->getUser(null, true);
            $newSession = $this->getClient()->getConnector()->getSession();
            if (!$token = $newSession->get('accessToken')) {
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
    public function getExternalHttpClient(): ClientInterface
    {
        $options = [
            'headers' => ['User-Agent' => $this->config->getUserAgent()],
            'debug' => false,
            'verify' => $this->config->getBool('api.skip_ssl') ? false : $this->caBundlePath(),
            'proxy' => $this->guzzleProxyConfig(),
            'timeout' => $this->config->getInt('api.default_timeout'),
        ];

        if (extension_loaded('zlib')) {
            $options['decode_content'] = true;
            $options['headers']['Accept-Encoding'] = 'gzip';
        }

        $stack = HandlerStack::create();
        $stack->push(new GuzzleDebugMiddleware($this->output, $this->config->getBool('api.debug')));
        $options['handler'] = $stack;

        return new Client($options);
    }

    /**
     * Get the current deployment for an environment.
     */
    public function getCurrentDeployment(Environment $environment, bool $refresh = false): EnvironmentDeployment
    {
        $cacheKey = implode(':', ['current-deployment', $environment->project, $environment->id, $environment->head_commit]);
        if (!$refresh && isset(self::$deploymentsCache[$cacheKey])) {
            return self::$deploymentsCache[$cacheKey];
        }
        $data = $this->cache->fetch($cacheKey);
        if ($data === false || $refresh) {
            try {
                /** @var EnvironmentDeployment $deployment */
                $deployment = $environment->getCurrentDeployment();
            } catch (EnvironmentStateException $e) {
                if ($e->getEnvironment()->status === 'inactive') {
                    throw new EnvironmentStateException('The environment is inactive', $e->getEnvironment());
                }
                throw $e;
            }
            $data = $deployment->getData();
            $data['_uri'] = $deployment->getUri();
            $this->cache->save($cacheKey, $data);
        } else {
            $this->io->debug('Loaded environment deployment from cache for environment: ' . $environment->id);
            $deployment = new EnvironmentDeployment($data, $data['_uri'], $this->getHttpClient(), true);
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
    public function hasCachedCurrentDeployment(Environment $environment): bool
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
    public function getDefaultEnvironment(array $envs, Project $project, bool $onlyDefaultBranch = false): ?Environment
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
        $prod = \array_filter($envs, fn(Environment $environment): bool => $environment->type === 'production');
        if (\count($prod) === 1) {
            return \reset($prod);
        }

        // Check if there is only one "main" environment.
        $main = \array_filter($envs, fn(Environment $environment) => $environment->is_main);
        if (\count($main) === 1) {
            return \reset($main);
        }

        return null;
    }

    /**
     * Get the preferred site URL for an environment and app.
     *
     * @param Environment $environment
     * @param string $appName
     * @param EnvironmentDeployment|null $deployment
     *
     * @return string|null
     */
    public function getSiteUrl(Environment $environment, string $appName, ?EnvironmentDeployment $deployment = null): ?string
    {
        $deployment = $deployment ?: $this->getCurrentDeployment($environment);
        $routes = Route::fromDeploymentApi($deployment->routes);

        // Return the first route that matches this app.
        // The routes will already have been sorted.
        $routes = \array_filter($routes, fn(Route $route): bool => $route->type === 'upstream' && $route->getUpstreamName() === $appName);
        $route = reset($routes);
        if ($route) {
            return $route->url;
        }

        // Fall back to the public-url property.
        if ($environment->hasLink('public-url')) {
            $data = $environment->getData();
            if (!empty($data['_links']['public-url']['href'])) {
                return $data['_links']['public-url']['href'];
            }
        }

        return null;
    }

    /**
     * React on an API 403 request.
     */
    private function on403(RequestInterface $request): void
    {
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/api/projects/')) {
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
     *
     * @throws RequestException
     *
     * @return false|Subscription
     *   The subscription or false if not found.
     */
    public function loadSubscription(string $id, ?Project $project = null): Subscription|false
    {
        $organizations_enabled = $this->config->getBool('api.organizations');
        if (!$organizations_enabled) {
            // Always load the subscription directly if the Organizations API
            // is not enabled.
            return $this->getClient()->getSubscription($id);
        }

        // Use the project's organization, if known.
        $organizationId = null;
        if (isset($project)) {
            $organizationId = $project->getProperty('organization', false, false);
        } else {
            foreach ($this->getMyProjects() as $info) {
                if ($info->subscription_id === $id) {
                    $organizationId = !empty($info->organization_ref->id) ? $info->organization_ref->id : false;
                    break;
                }
            }
        }
        if (empty($organizationId)) {
            $this->io->debug('Failed to find the organization ID for the subscription: ' . $id);
            return false;
        }
        $organization = $this->getOrganizationById($organizationId);
        if (!$organization) {
            $this->io->debug('Project organization not found: ' . $organizationId);
            return false;
        }
        $subscription = $organization->getSubscription($id);
        if (!$subscription) {
            $this->io->debug('Failed to load subscription: ' . $id);
            return false;
        }

        return $subscription;
    }

    /**
     * Returns whether the user is required to verify their phone number before certain actions.
     *
     * @return array{state: bool, type: string}
     */
    public function checkUserVerification(): array
    {
        if (!$this->config->getBool('api.user_verification')) {
            return ['state' => false, 'type' => ''];
        }

        // Check the API to see if verification is required.
        $request = new Request('POST', '/me/verification');
        $response = $this->getHttpClient()->send($request);
        return (array) Utils::jsonDecode((string) $response->getBody(), true);
    }

    /**
     * Returns whether the user is allowed to create a project under an organization.
     *
     * @return array{can_create: bool, message: string, required_action: ?array{action: string, type: string}}
     */
    public function checkCanCreate(Organization $org): array
    {
        $request = new Request('GET', $org->getUri() . '/subscriptions/can-create');
        $response = $this->getHttpClient()->send($request);
        return (array) Utils::jsonDecode((string) $response->getBody(), true);
    }

    /**
     * Returns a descriptive label for a referenced user.
     */
    public function getUserRefLabel(UserRef $userRef, string|false $tag = 'info'): string
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
     */
    public function getOrganizationById(string $id, bool $reset = false): Organization|false
    {
        $cacheKey = 'organization:' . $id;
        if (!$reset && ($cached = $this->cache->fetch($cacheKey))) {
            $this->io->debug('Loaded organization from cache: ' . $id);
            return new Organization($cached, $cached['_url'], $this->getHttpClient());
        }
        $organization = $this->getClient()->getOrganizationById($id);
        if ($organization) {
            $data = $organization->getData();
            $data['_url'] = $organization->getUri();
            $this->cache->save($cacheKey, $data, $this->config->getInt('api.orgs_ttl'));
        }
        return $organization;
    }

    /**
     * Loads an organization by name, with caching.
     */
    public function getOrganizationByName(string $name, bool $reset = false): Organization|false
    {
        return $this->getOrganizationById('name=' . $name, $reset);
    }

    /**
     * Clears the cache for an organization.
     *
     * @param Organization $org
     * @return void
     */
    public function clearOrganizationCache(Organization $org): void
    {
        $this->cache->delete('organization:' . $org->id);
        $this->cache->delete('organization:name=' . $org->name);
    }

    /**
     * Returns the Console URL for a project, with caching.
     */
    public function getConsoleURL(Project $project, bool $reset = false): string
    {
        if ($this->config->has('service.console_url') && $this->config->getBool('api.organizations')) {
            // Load the organization name if possible.
            $firstSegment = $organizationId = $project->getProperty('organization');
            try {
                $organization = $this->getOrganizationById($organizationId, $reset);
                if ($organization) {
                    $firstSegment = $organization->name;
                }
            } catch (BadResponseException $e) {
                if ($e->getResponse()->getStatusCode() === 403) {
                    trigger_error($e->getMessage(), E_USER_WARNING);
                } else {
                    throw $e;
                }
            }

            return ltrim($this->config->getStr('service.console_url'), '/') . '/' . rawurlencode((string) $firstSegment) . '/' . rawurlencode($project->id);
        } elseif ($subscription = $this->loadSubscription((string) $project->getSubscriptionId(), $project)) {
            return $subscription->project_ui;
        }
        throw new \RuntimeException('Failed to load Console URL for project: ' . $project->id);
    }

    /**
     * Loads an organization member by email, by paging through all the members in the organization.
     *
     * @TODO replace this with a more efficient API when available
     */
    public function loadMemberByEmail(Organization $organization, string $email): ?Member
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
     * @return Member[]
     */
    public function listMembers(Organization $organization, bool $reset = false): array
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
     */
    public function getMemberLabel(Member|TeamMember $member): string
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
    public function supportsSizingApi(Project $project, ?EnvironmentDeployment $deployment = null): bool
    {
        if (isset($deployment->project_info['settings'])) {
            return !empty($deployment->project_info['settings']['sizing_api_enabled']);
        }
        $settings = $this->getProjectSettings($project);
        return !empty($settings['sizing_api_enabled']);
    }

    /**
     * Checks if a project supports the Autoscaling API.
     */
    public function supportsAutoscaling(Project $project): bool
    {
        $capabilities = $this->getProjectCapabilities($project);
        return !empty($capabilities->autoscaling['enabled']);
    }

    /**
     * Returns project settings.
     *
     * Settings are cached between calls unless a refresh is forced.
     *
     * @param bool $refresh
     *
     * @return array<string, mixed>
     */
    public function getProjectSettings(Project $project, bool $refresh = false): array
    {
        $cacheKey = 'project-settings:' . $project->id;
        $cachedSettings = $this->cache->fetch($cacheKey);
        if (!empty($cachedSettings) && !$refresh) {
            return $cachedSettings;
        }
        $request = new Request('GET', $project->getUri() . '/settings');
        $response = $this->getHttpClient()->send($request);
        $settings = (array) Utils::jsonDecode((string) $response->getBody(), true);
        $this->cache->save($cacheKey, $settings, $this->config->getInt('api.projects_ttl'));
        return $settings;
    }

    /**
     * Returns project capabilities.
     *
     * Capabilities are cached between calls unless a refresh is forced.
     *
     * @param bool $refresh
     *
     * @return Capabilities
     */
    public function getProjectCapabilities(Project $project, bool $refresh = false): Capabilities
    {
        $cacheKey = 'project-capabilities:' . $project->id;
        $cachedCapabilities = $this->cache->fetch($cacheKey);
        if (!empty($cachedCapabilities) && !$refresh) {
            return $cachedCapabilities;
        }

        $capabilities = $project->getCapabilities();
        $this->cache->save($cacheKey, $capabilities, $this->config->getInt('api.projects_ttl'));
        return $capabilities;
    }

    /**
     * Loads the code source integration for a project.
     *
     * @param Project $project
     * @return Integration|null
     */
    public function getCodeSourceIntegration(Project $project): ?Integration
    {
        $codeSourceIntegrationTypes = ['github', 'gitlab', 'bitbucket', 'bitbucket_server'];
        foreach ($project->getIntegrations() as $integration) {
            if (in_array($integration->type, $codeSourceIntegrationTypes)) {
                return $integration;
            }
        }
        return null;
    }

    /**
     * Shows information about the currently logged in user and their session, if applicable.
     *
     * @param bool $logout  Whether this should avoid re-authentication (if an API token is set).
     * @param bool $newline Whether to prepend a newline if there is output.
     */
    public function showSessionInfo(bool $logout = false, bool $newline = true): void
    {
        $sessionId = $this->config->getSessionId();
        if ($sessionId !== 'default' || count($this->listSessionIds()) > 1) {
            if ($newline) {
                $this->stdErr->writeln('');
                $newline = false;
            }
            $this->stdErr->writeln(sprintf('The current session ID is: <info>%s</info>', $sessionId));
            if (!$this->config->isSessionIdFromEnv()) {
                $this->stdErr->writeln(sprintf('Change this using: <info>%s session:switch</info>', $this->config->getStr('application.executable')));
            }
        }
        if (!$logout && $this->isLoggedIn()) {
            if ($newline) {
                $this->stdErr->writeln('');
            }
            $account = $this->getMyAccount();
            $this->stdErr->writeln(\sprintf(
                'You are logged in as <info>%s</info> (<info>%s</info>)',
                $account['username'],
                $account['email'],
            ));
        }
    }

    /**
     * Returns the URL to view autoscaling settings for the selected environment.
     *
     * @param Environment $environment
     * @param bool $manage
     *
     * @return string|false
     *   The url to the autoscaling settings endpoint or false on failure.
     */
    public function getAutoscalingSettingsLink(Environment $environment, bool $manage = false): string|false
    {
        $rel = "#autoscaling";
        if ($manage === true) {
            $rel = "#manage-autoscaling";
        }

        if (!$environment->hasLink($rel)) {
            $this->io->debug(\sprintf(
                'The environment <comment>%s</comment> is missing the link <comment>%s</comment>',
                $environment->id,
                $rel
            ));

            return false;
        }

        return $environment->getLink($rel);
    }

    /**
     * Returns the autoscaling settings for the selected environment.
     *
     * @param Environment $environment
     *
     * @return \Platformsh\Client\Model\AutoscalingSettings|false
     *  The autoscaling settings for the environment or false on failure.
     */
    public function getAutoscalingSettings(Environment $environment)
    {
        $autoscalingSettingsLink = $this->getAutoscalingSettingsLink($environment);
        if (!$autoscalingSettingsLink) {
            return false;
        }

        try {
            $result = $environment->runOperation('autoscaling', 'get');
        } catch (EnvironmentStateException $e) {
            if ($e->getEnvironment()->status === 'inactive') {
                throw new EnvironmentStateException('The environment is inactive', $e->getEnvironment());
            }
            return false;
        }
        return new AutoscalingSettings($result->getData(), $autoscalingSettingsLink);
    }

    /**
     * Configures the autoscaling settings for the selected environment.
     *
     * @param Environment $environment
     * @param array<string, mixed> $settings
     */
    public function setAutoscalingSettings(Environment $environment, array $settings): void
    {
        if (!$this->getAutoscalingSettingsLink($environment, true)) {
            throw new EnvironmentStateException('Managing autoscaling settings is not currently available', $environment);
        }

        try {
            $environment->runOperation('manage-autoscaling', 'patch', $settings);
        } catch (EnvironmentStateException $e) {
            if ($e->getEnvironment()->status === 'inactive') {
                throw new EnvironmentStateException('The environment is inactive', $e->getEnvironment());
            }
            throw $e;
        }
    }

    /**
     * Warn the user if a project is suspended.
     *
     * @param Project $project
     */
    public function warnIfSuspended(Project $project): void
    {
        if ($project->isSuspended()) {
            $this->stdErr->writeln('This project is <error>suspended</error>.');
            if ($this->config->getBool('warnings.project_suspended_payment')) {
                $orgId = $project->getProperty('organization', false);
                if ($orgId) {
                    try {
                        $organization = $this->getClient()->getOrganizationById($orgId);
                    } catch (BadResponseException) {
                        $organization = false;
                    }
                    if ($organization && $organization->hasLink('payment-source')) {
                        $this->stdErr->writeln(sprintf('To re-activate it, update the payment details for your organization, %s.', $this->getOrganizationLabel($organization, 'comment')));
                    }
                } elseif ($project->owner === $this->getMyUserId()) {
                    $this->stdErr->writeln('To re-activate it, update your payment details.');
                }
            }
        }
    }

    /**
     * Warn the user that the remote environment needs redeploying.
     */
    public function redeployWarning(): void
    {
        $this->stdErr->writeln([
            '',
            '<comment>The remote environment(s) must be redeployed for the change to take effect.</comment>',
            'To redeploy an environment, run: <info>' . $this->config->getStr('application.executable') . ' redeploy</info>',
        ]);
    }

    /**
     * Lists services in a deployment.
     *
     * @param EnvironmentDeployment $deployment
     *
     * @return array<string, WebApp|Worker|Service>
     *     An array of services keyed by the service name.
     */
    private function allServices(EnvironmentDeployment $deployment): array
    {
        $webapps = $deployment->webapps;
        $workers = $deployment->workers;
        $services = $deployment->services;
        ksort($webapps, SORT_STRING | SORT_FLAG_CASE);
        ksort($workers, SORT_STRING | SORT_FLAG_CASE);
        ksort($services, SORT_STRING | SORT_FLAG_CASE);
        return array_merge($webapps, $workers, $services);
    }

    /**
     * Checks if a project supports guaranteed resources.
     */
    public function supportsGuaranteedCPU(Project $project, ?EnvironmentDeployment $deployment = null): bool
    {
        if ($deployment && ($info = $deployment->getProperty('project_info', false))) {
            $settings = $info['settings'];
            $capabilities = $info['capabilities'];
        } else {
            $settings = $this->getProjectSettings($project);
            $capabilities = $this->getProjectCapabilities($project);
        }

        return !empty($settings['enable_guaranteed_resources']) && !empty($capabilities['guaranteed_resources']['enabled']);
    }

    /**
     * Check if an environment has guaranteed CPU.
     */
    public function environmentHasGuaranteedCPU(Environment $environment, ?Project $project = null): bool
    {
        if (!$this->supportsGuaranteedCPU($project)) {
            return false;
        }

        $deployment = $this->getCurrentDeployment($environment);
        $containerProfiles = $deployment->container_profiles;
        $services = $this->allServices($deployment);
        foreach ($services as $service) {
            $properties = $service->getProperties();
            if (isset($properties['container_profile']) && isset($containerProfiles[$properties['container_profile']][$properties['resources']['profile_size']])) {
                $profileInfo = $containerProfiles[$properties['container_profile']][$properties['resources']['profile_size']];
                if (isset($profileInfo['cpu_type']) && $profileInfo['cpu_type'] === 'guaranteed') {
                    return true;
                }
            }
        }

        return false;
    }
}
