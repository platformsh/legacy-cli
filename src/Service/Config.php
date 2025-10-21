<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Composer\CaBundle\CaBundle;
use Platformsh\Cli\Util\NestedArrayUtil;
use Symfony\Component\Yaml\Yaml;

/**
 * Configuration used throughout the CLI.
 */
class Config
{
    /** @var array<string, mixed> */
    private array $config;
    private string $configFile;

    /** @var array<string, string> */
    private array $env;

    private ?Filesystem $fs = null;
    private ?string $version = null;
    private ?string $homeDir = null;

    /**
     * @param array<string, string>|null $env
     * @param string|null $file
     */
    public function __construct(?array $env = null, ?string $file = null)
    {
        $this->env = $env !== null ? $env : getenv();

        if ($file === null) {
            $file = $this->getEnv('CLI_CONFIG_FILE', false) ?: CLI_ROOT . '/config.yaml';
        }

        $this->configFile = $file;

        $defaultsFile = CLI_ROOT . '/config-defaults.yaml';
        $defaults = $this->loadConfigFromFile($defaultsFile);

        $config = $this->loadConfigFromFile($this->configFile);

        // Merge the configuration with the defaults.
        $this->config = array_replace_recursive($defaults, $config);

        $this->applyUserConfigOverrides();
        $this->applyEnvironmentOverrides();
        $this->applyDynamicDefaults();

        // Load the session ID from a file.
        if ($this->getEnv('SESSION_ID') === false) {
            $sessionIdFile = $this->getSessionIdFile();
            if (\file_exists($sessionIdFile)) {
                $id = \file_get_contents($sessionIdFile);
                if ($id !== false) {
                    try {
                        $this->validateSessionId(\trim($id));
                    } catch (\InvalidArgumentException) {
                        throw new \InvalidArgumentException('Invalid session ID in file: ' . $sessionIdFile);
                    }
                    $this->config['api']['session_id'] = \trim($id);
                }
            }
        }

        // Validate the session ID.
        if (isset($this->config['api']['session_id'])) {
            $this->validateSessionId($this->config['api']['session_id']);
        }
    }

    /**
     * Check if a configuration value is defined.
     *
     * @param string $name    The configuration name (e.g. 'application.name').
     * @param bool   $notNull Set false to treat null configuration values as
     *                        defined.
     *
     * @return bool
     */
    public function has(string $name, bool $notNull = true): bool
    {
        $value = NestedArrayUtil::getNestedArrayValue($this->config, explode('.', $name), $exists);

        return $exists && (!$notNull || $value !== null);
    }

    /**
     * Get a configuration value.
     *
     * @param string $name The configuration name (e.g. 'application.name').
     *
     * @throws \RuntimeException if the configuration is not defined.
     *
     * @return null|string|bool|array<mixed>|int|float
     */
    public function get(string $name): bool|array|string|null|int|float
    {
        $value = NestedArrayUtil::getNestedArrayValue($this->config, explode('.', $name), $exists);
        if (!$exists) {
            throw new \RuntimeException('Configuration not defined: ' . $name);
        }

        return $value;
    }

    /**
     * Get a string configuration value.
     *
     * @param string $name The configuration name (e.g. 'application.name').
     *
     * @throws \RuntimeException if the configuration is not defined or not a string.
     *
     * @return string
     */
    public function getStr(string $name): string
    {
        $value = $this->get($name);
        if (!is_string($value)) {
            if ($value === null) {
                return '';
            }
            throw new \RuntimeException(sprintf('Configuration %s was expected to be a string, %s found', $name, gettype($value)));
        }

        return $value;
    }

    /**
     * Get an integer configuration value.
     *
     * @param string $name The configuration name (e.g. 'api.default_timeout').
     *
     * @throws \RuntimeException if the configuration is not defined or not an integer.
     *
     * @return int
     */
    public function getInt(string $name): int
    {
        $value = $this->get($name);
        if (!is_int($value) && (!is_string($value) || (int) $value != $value)) {
            throw new \RuntimeException(sprintf('Configuration %s was expected to be an integer, %s found', $name, gettype($value)));
        }

        return (int) $value;
    }

    /**
     * Get a Boolean configuration value.
     *
     * @param string $name The configuration name (e.g. 'api.sizing').
     *
     * @throws \RuntimeException if the configuration is not defined or not a Boolean, 1 or 0.
     *
     * @return bool
     */
    public function getBool(string $name): bool
    {
        $value = $this->get($name);
        if ((bool) $value != $value) {
            throw new \RuntimeException(sprintf('Configuration %s of type %s could not be cast to true or false.', $name, gettype($value)));
        }

        return (bool) $value;
    }

    /**
     * Get a configuration value, specifying a default if it does not exist.
     *
     * @param string $name
     * @param mixed $default
     *   A default. This can be overridden by the config-defaults.yaml file.
     * @param bool $useDefaultIfNull
     *   Whether to use the default if the current value is null.
     *
     * @return mixed
     */
    public function getWithDefault(string $name, mixed $default, bool $useDefaultIfNull = true): mixed
    {
        $value = NestedArrayUtil::getNestedArrayValue($this->config, explode('.', $name), $exists);
        if (!$exists || ($useDefaultIfNull && $value === null)) {
            return $default;
        }

        return $value;
    }

    /**
     * Returns the user's home directory.
     *
     * @param bool $reset Reset the static cache.
     *
     * @return string The absolute path to the user's home directory
     */
    public function getHomeDirectory(bool $reset = false): string
    {
        if (!$reset && isset($this->homeDir)) {
            return $this->homeDir;
        }
        $prefix = $this->config['application']['env_prefix'] ?? '';
        $envVars = [$prefix . 'HOME', 'HOME', 'USERPROFILE'];
        foreach ($envVars as $envVar) {
            $value = getenv($envVar);
            if (array_key_exists($envVar, $this->env)) {
                $value = $this->env[$envVar];
            }
            if (is_string($value) && $value !== '') {
                if (!is_dir($value)) {
                    throw new \RuntimeException(
                        sprintf('Invalid environment variable %s: %s (not a directory)', $envVar, $value),
                    );
                }
                $this->homeDir = realpath($value) ?: $value;
                return $this->homeDir;
            }
        }

        throw new \RuntimeException(sprintf('Could not determine home directory (environment variables checked: %s)', implode(', ', $envVars)));
    }

    /**
     * Get the directory where the CLI is normally installed and configured.
     *
     * @param bool $absolute Whether to return an absolute path. If false,
     *                       the path will be relative to the home directory.
     *
     * @return string
     */
    public function getUserConfigDir(bool $absolute = true): string
    {
        $path = $this->getStr('application.user_config_dir');

        return $absolute ? $this->getHomeDirectory() . DIRECTORY_SEPARATOR . $path : $path;
    }

    private function fs(): Filesystem
    {
        if (!isset($this->fs)) {
            $this->fs = new Filesystem();
        }
        return $this->fs;
    }

    /**
     * Returns a directory where user-specific files can be written.
     *
     * This may be for storing state, logs, credentials, etc.
     *
     * @return string
     */
    public function getWritableUserDir(): string
    {
        $path = $this->config['application']['writable_user_dir'] ?? $this->getUserConfigDir(false);
        $configDir = $this->getHomeDirectory() . DIRECTORY_SEPARATOR . $path;

        // If the directory is not writable (e.g. if we are on a Platform.sh
        // environment), use a temporary directory instead.
        if (!$this->fs()->canWrite($configDir) || (file_exists($configDir) && !is_dir($configDir))) {
            return sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->getStr('application.tmp_sub_dir');
        }

        return $configDir;
    }

    public function getSessionDir(bool $subDir = false): string
    {
        $sessionDir = $this->getWritableUserDir() . DIRECTORY_SEPARATOR . '.session';
        if ($subDir) {
            return $sessionDir . DIRECTORY_SEPARATOR . $this->getSessionIdSlug();
        }

        return $sessionDir;
    }

    /**
     * @return string
     */
    public function getSessionId(): string
    {
        return $this->getWithDefault('api.session_id', 'default');
    }

    /**
     * @param string $prefix
     *
     * @return string
     */
    public function getSessionIdSlug(string $prefix = 'sess-cli-'): string
    {
        return $prefix . preg_replace('/[^\w\-]+/', '-', $this->getSessionId());
    }

    /**
     * Sets a new session ID.
     */
    public function setSessionId(string $id, bool $persist = false): void
    {
        $this->config['api']['session_id'] = $id;
        if ($persist) {
            $filename = $this->getSessionIdFile();
            if ($id === 'default') {
                $this->fs()->remove($filename);
            } else {
                $this->fs()->writeFile($filename, $id, false);
            }
        }
    }

    /**
     * Returns whether the session ID was set via the environment.
     *
     * This means that the session:switch command cannot be used.
     *
     * @return bool
     */
    public function isSessionIdFromEnv(): bool
    {
        $sessionId = $this->getSessionId();
        return $sessionId !== 'default' && $sessionId === $this->getEnv('SESSION_ID');
    }

    /**
     * Returns the path to a file where the session ID is saved.
     *
     * @return string
     */
    private function getSessionIdFile(): string
    {
        return $this->getWritableUserDir() . DIRECTORY_SEPARATOR . 'session-id';
    }

    /**
     * Validates a user-provided session ID.
     *
     * @param string $id
     */
    public function validateSessionId(string $id): void
    {
        if (str_starts_with($id, 'api-token-') || !\preg_match('@^[a-z0-9_-]+$@i', $id)) {
            throw new \InvalidArgumentException('Invalid session ID: ' . $id);
        }
    }

    /**
     * Returns a new Config instance with overridden values.
     *
     * @param array<string, mixed> $overrides
     *
     * @return self
     */
    public function withOverrides(array $overrides): self
    {
        $config = new self($this->env, $this->configFile);
        foreach ($overrides as $key => $value) {
            NestedArrayUtil::setNestedArrayValue($config->config, explode('.', $key), $value);
        }

        return $config;
    }

    /**
     * Loads configuration from a file and parses it.
     *
     * @param string $filename
     *
     * @return array<string, mixed>
     */
    private function loadConfigFromFile(string $filename): array
    {
        $contents = file_get_contents($filename);
        if ($contents === false) {
            if (!file_exists($filename)) {
                throw new \InvalidArgumentException('Config file not found: ' . $filename);
            }
            if (!is_readable($filename)) {
                throw new \RuntimeException('Config file not readable: ' . $filename);
            }
            throw new \RuntimeException('Failed to read config file: ' . $filename);
        }

        return (array) Yaml::parse($contents);
    }

    private function applyEnvironmentOverrides(): void
    {
        $overrideMap = [];
        $types = [];
        foreach ($this->config as $section => $sub_config) {
            if (\is_array($sub_config)) {
                foreach ($sub_config as $sub_section => $value) {
                    if (\is_scalar($value) || $value === null) {
                        $varName = \strtoupper($section . '_' . $sub_section);
                        $accessorName = $section . '.' . $sub_section;
                        $overrideMap[$varName] = $accessorName;
                        $types[$varName] = gettype($value);
                    }
                }
            }
        }
        $overrideMap = \array_merge($overrideMap, [
            'TOKEN' => 'api.token',
            'API_TOKEN' => 'api.access_token', // Deprecated
            'COPY_ON_WINDOWS' => 'local.copy_on_windows',
            'DEBUG' => 'api.debug',
            'DISABLE_CACHE' => 'api.disable_cache',
            'DISABLE_LOCKS' => 'api.disable_locks',
            'DRUSH' => 'local.drush_executable',
            'SESSION_ID' => 'api.session_id',
            'SKIP_SSL' => 'api.skip_ssl',
            'ACCOUNTS_API' => 'api.accounts_api_url',
            'API_URL' => 'api.base_url',
            'DEFAULT_TIMEOUT' => 'api.default_timeout',
            'AUTH_URL' => 'api.auth_url',
            'OAUTH2_AUTH_URL' => 'api.oauth2_auth_url',
            'OAUTH2_CLIENT_ID' => 'api.oauth2_client_id',
            'OAUTH2_TOKEN_URL' => 'api.oauth2_token_url',
            'OAUTH2_REVOKE_URL' => 'api.oauth2_revoke_url',
            'CERTIFIER_URL' => 'api.certifier_url',
            'AUTO_LOAD_SSH_CERT' => 'ssh.auto_load_cert',
            'API_AUTO_LOAD_SSH_CERT' => 'ssh.auto_load_cert',
            'USER_AGENT' => 'api.user_agent',
            'API_WRITE_USER_SSH_CONFIG' => 'ssh.write_user_config',
            'API_ADD_TO_SSH_AGENT' => 'ssh.add_to_agent',
            'SSH_OPTIONS' => 'ssh.options',
            'SSH_HOST_KEYS' => 'ssh.host_keys',
        ]);

        foreach ($overrideMap as $var => $key) {
            $value = $this->getEnv($var);
            if ($value !== false) {
                // Environment variables can only be strings. Attempt to
                // convert the type of numeric ones.
                if (isset($types[$var]) && is_numeric($value)) {
                    if ($types[$var] === 'boolean') {
                        $value = (bool) $value;
                    } elseif ($types[$var] === 'integer') {
                        $value = (int) $value;
                    } elseif ($types[$var] === 'double') {
                        $value = (float) $value;
                    }
                }
                NestedArrayUtil::setNestedArrayValue($this->config, explode('.', $key), $value, true);
            }
        }

        // Special case: replace the list ssh.domain_wildcards with the value of {PREFIX}SSH_DOMAIN_WILDCARD.
        if (($value = $this->getEnv('SSH_DOMAIN_WILDCARD')) !== false) {
            $this->config['ssh']['domain_wildcards'] = [$value];
        }

        // Special case: {PREFIX}NO_LEGACY_WARNING disables the migration prompt.
        if ($this->getEnv('NO_LEGACY_WARNING')) {
            $this->config['migrate']['prompt'] = false;
        }

        // Special case: replace the list api.organization_types with the (split) value of {PREFIX}API_ORGANIZATION_TYPES.
        if (($value = $this->getEnv('API_ORGANIZATION_TYPES')) !== false) {
            $this->config['api']['organization_types'] = $value === '' ? [] : \preg_split('/[,\s]+/', $value);
        }
    }

    /**
     * Get an environment variable
     *
     * @param string $name
     *   The variable name.
     * @param bool $addPrefix
     *   Whether to add the configured prefix to the variable name.
     *
     * @return string|false
     *   The value of the environment variable, or false if it is not set.
     */
    private function getEnv(string $name, bool $addPrefix = true): string|false
    {
        $prefix = $addPrefix && isset($this->config['application']['env_prefix']) ? $this->config['application']['env_prefix'] : '';
        if (array_key_exists($prefix . $name, $this->env)) {
            return $this->env[$prefix . $name];
        }

        return getenv($prefix . $name);
    }

    private function applyUserConfigOverrides(): void
    {
        $userConfigFile = $this->getUserConfigDir() . '/config.yaml';
        if (!file_exists($userConfigFile)) {
            return;
        }
        $userConfig = $this->loadConfigFromFile($userConfigFile);
        if (!empty($userConfig)) {
            $this->config = array_replace_recursive($this->config, $userConfig);
        }
    }

    /**
     * Tests if an experiment (a feature flag) is enabled.
     */
    public function isExperimentEnabled(string $name): bool
    {
        return !empty($this->config['experimental']['all_experiments']) || !empty($this->config['experimental'][$name]);
    }

    /**
     * Tests if a command should be hidden.
     */
    public function isCommandHidden(string $name): bool
    {
        return (!empty($this->config['application']['hidden_commands'])
            && in_array($name, $this->config['application']['hidden_commands']));
    }

    /**
     * Tests if a command should be enabled.
     */
    public function isCommandEnabled(string $name): bool
    {
        if (!empty($this->config['application']['disabled_commands'])
            && in_array($name, $this->config['application']['disabled_commands'])) {
            return false;
        }
        if ($this->isWrapped()
            && !empty($this->config['application']['wrapped_disabled_commands'])
            && in_array($name, $this->config['application']['wrapped_disabled_commands'])) {
            return false;
        }
        if (!empty($this->config['application']['experimental_commands'])
            && in_array($name, $this->config['application']['experimental_commands'])) {
            return !empty($this->config['experimental']['all_experiments'])
                || (
                    !empty($this->config['experimental']['enable_commands'])
                    && in_array($name, $this->config['experimental']['enable_commands'])
                );
        }

        return true;
    }

    /**
     * Returns this application version.
     */
    public function getVersion(): string
    {
        if (isset($this->version)) {
            return $this->version;
        }
        $version = $this->getWithDefault('application.version', '@version-placeholder@');
        if (str_starts_with((string) $version, '@') && str_ends_with((string) $version, '@')) {
            // Silently try getting the version from Git.
            $tag = (new Shell())->execute(['git', 'describe', '--tags'], CLI_ROOT);
            if (is_string($tag) && str_starts_with($tag, 'v')) {
                $version = trim($tag);
            }
        }
        $this->version = $version;

        return $version;
    }

    /**
     * Returns an HTTP User Agent string representing this application.
     *
     * @return string
     */
    public function getUserAgent(): string
    {
        $template = $this->getWithDefault(
            'api.user_agent',
            '{APP_NAME_DASH}/{VERSION} ({UNAME_S}; {UNAME_R}; PHP {PHP_VERSION})',
        );
        /** @var array<string, string> $replacements */
        $replacements = [
            '{APP_NAME_DASH}' => \str_replace(' ', '-', $this->getStr('application.name')),
            '{APP_NAME}' => $this->getStr('application.name'),
            '{APP_SLUG}' => $this->getStr('application.slug'),
            '{VERSION}' => $this->getVersion(),
            '{UNAME_S}' => \php_uname('s'),
            '{UNAME_R}' => \php_uname('r'),
            '{PHP_VERSION}' => PHP_VERSION,
        ];
        return \str_replace(\array_keys($replacements), \array_values($replacements), $template);
    }

    /**
     * Finds proxy addresses based on the http_proxy and https_proxy environment variables.
     *
     * @return array{https?: string, http?: string}
     */
    public function getProxies(): array
    {
        $proxies = [];
        if (\getenv('https_proxy') !== false) {
            $proxies['https'] = \getenv('https_proxy');
        }
        // An environment variable prefixed by 'http_' cannot be trusted in a non-CLI (web) context.
        if (PHP_SAPI === 'cli' && \getenv('http_proxy') !== false) {
            $proxies['http'] = \getenv('http_proxy');
        }
        return $proxies;
    }

    /**
     * Returns an array of context options for HTTP/HTTPS streams.
     *
     * @return array{http: array<string, mixed>, ssl?: array<string, mixed>}
     */
    public function getStreamContextOptions(int|float|null $timeout = null): array
    {
        $opts = [
            // See https://www.php.net/manual/en/context.http.php
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout !== null ? $timeout : $this->getInt('api.default_timeout'),
                'user_agent' => $this->getUserAgent(),
            ],
        ];

        // The PHP stream context only accepts a single proxy option, under the schemes 'tcp' or 'ssl'.
        $proxies = $this->getProxies();
        foreach ($proxies as $proxyUrl) {
            $opts['http']['proxy'] = \str_replace(['http://', 'https://'], ['tcp://', 'ssl://'], $proxyUrl);
            break;
        }

        // Set up SSL options.
        if ($this->getBool('api.skip_ssl')) {
            $opts['ssl']['verify_peer'] = false;
            $opts['ssl']['verify_peer_name'] = false;
        } else {
            $caBundlePath = CaBundle::getSystemCaRootBundlePath();
            if (\is_dir($caBundlePath)) {
                $opts['ssl']['capath'] = $caBundlePath;
            } else {
                $opts['ssl']['cafile'] = $caBundlePath;
            }
        }

        return $opts;
    }

    /**
     * Detects if the CLI is running wrapped inside the go wrapper.
     *
     * @return bool
     */
    public function isWrapped(): bool
    {
        return getenv($this->getStr('application.env_prefix') . 'WRAPPED') === '1';
    }

    /**
     * Returns all the current configuration.
     *
     * @return array<string, mixed>
     */
    public function getAll(): array
    {
        return $this->config;
    }

    /**
     * Applies defaults values based on other config values.
     */
    private function applyDynamicDefaults(): void
    {
        $this->applyUrlDefaults();
        $this->applyLocalDirectoryDefaults();

        if (!isset($this->config['application']['slug'])) {
            $this->config['application']['slug'] = preg_replace('/[^a-z0-9-]+/', '-', str_replace(['.', ' '], ['', '-'], strtolower($this->getStr('application.name'))));
        }
        if (!isset($this->config['application']['tmp_sub_dir'])) {
            $this->config['application']['tmp_sub_dir'] = $this->getStr('application.slug') . '-tmp';
        }
        if (!isset($this->config['api']['oauth2_client_id'])) {
            $this->config['api']['oauth2_client_id'] = $this->getStr('application.slug');
        }
        if (!isset($this->config['detection']['console_domain']) && isset($this->config['service']['console_url'])) {
            $consoleDomain = parse_url((string) $this->config['service']['console_url'], PHP_URL_HOST);
            if ($consoleDomain !== false) {
                $this->config['detection']['console_domain'] = $consoleDomain;
            }
        }
        if (!isset($this->config['service']['applications_config_file'])) {
            $this->config['service']['applications_config_file'] = $this->getStr('service.project_config_dir') . '/applications.yaml';
        }

        // Migrate renamed config keys.
        if (isset($this->config['api']['add_to_ssh_agent']) && !isset($this->config['ssh']['add_to_agent'])) {
            $this->config['ssh']['add_to_agent'] = $this->config['api']['add_to_ssh_agent'];
        }
        if (isset($this->config['api']['auto_load_ssh_cert']) && !isset($this->config['ssh']['auto_load_cert'])) {
            $this->config['ssh']['auto_load_cert'] = $this->config['api']['auto_load_ssh_cert'];
        }
        if (isset($this->config['api']['ssh_domain_wildcards']) && !isset($this->config['ssh']['domain_wildcards'])) {
            $this->config['ssh']['domain_wildcards'] = $this->config['api']['ssh_domain_wildcards'];
        }
        if (isset($this->config['service']['header_prefix']) && !isset($this->config['detection']['cluster_header'])) {
            $this->config['detection']['cluster_header'] = $this->config['service']['header_prefix'] . '-Cluster';
        }
    }

    private function applyUrlDefaults(): void
    {
        $authUrl = $this->getWithDefault('api.auth_url', '');
        if ($authUrl === '') {
            return;
        }
        $defaultsUnderAuthUrl = [
            'oauth2_auth_url' => '/oauth2/authorize',
            'oauth2_token_url' => '/oauth2/token',
            'oauth2_revoke_url' => '/oauth2/revoke',
            'certifier_url' => '',
        ];
        foreach ($defaultsUnderAuthUrl as $apiSubKey => $path) {
            if (!isset($this->config['api'][$apiSubKey])) {
                $this->config['api'][$apiSubKey] = rtrim((string) $authUrl, '/') . $path;
            }
        }
    }

    private function applyLocalDirectoryDefaults(): void
    {
        if (isset($this->config['local']['local_dir'])) {
            $localDir = $this->config['local']['local_dir'];
        } else {
            $localDir = $this->getStr('service.project_config_dir') . DIRECTORY_SEPARATOR . 'local';
            $this->config['local']['local_dir'] = $localDir;
        }
        $defaultsUnderLocalDir = [
            'archive_dir' => 'build-archives',
            'build_dir' => 'builds',
            'dependencies_dir' => 'deps',
            'project_config' => 'project.yaml',
            'shared_dir' => 'shared',
        ];
        foreach ($defaultsUnderLocalDir as $localSubKey => $subPath) {
            if (!isset($this->config['local'][$localSubKey])) {
                $this->config['local'][$localSubKey] = $localDir . DIRECTORY_SEPARATOR . $subPath;
            }
        }
    }

    /**
     * Returns the base API URL.
     *
     * @return string
     */
    public function getApiUrl(): string
    {
        return $this->getStr('api.base_url');
    }
}
