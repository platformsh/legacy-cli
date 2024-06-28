<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Util\NestedArrayUtil;
use Symfony\Component\Yaml\Yaml;

/**
 * Configuration used throughout the CLI.
 */
class Config
{
    private $config;
    private $configFile;
    private $env;
    private $fs;
    private $version;
    private $homeDir;

    /**
     * @param array|null  $env
     * @param string|null $file
     */
    public function __construct(array $env = null, $file = null)
    {
        $this->env = $env !== null ? $env : $this->getDefaultEnv();

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
                    } catch (\InvalidArgumentException $e) {
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
     * Find all current environment variables.
     *
     * @return array
     */
    private function getDefaultEnv()
    {
        return PHP_VERSION_ID >= 70100 ? getenv() : $_ENV;
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
    public function has($name, $notNull = true)
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
     * @return null|string|bool|array
     */
    public function get($name)
    {
        $value = NestedArrayUtil::getNestedArrayValue($this->config, explode('.', $name), $exists);
        if (!$exists) {
            throw new \RuntimeException('Configuration not defined: ' . $name);
        }

        return $value;
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
    public function getWithDefault($name, $default, $useDefaultIfNull = true)
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
    public function getHomeDirectory($reset = false)
    {
        if (!$reset && isset($this->homeDir)) {
            return $this->homeDir;
        }
        $prefix = isset($this->config['application']['env_prefix']) ? $this->config['application']['env_prefix'] : '';
        $envVars = [$prefix . 'HOME', 'HOME', 'USERPROFILE'];
        foreach ($envVars as $envVar) {
            $value = getenv($envVar);
            if (array_key_exists($envVar, $this->env)) {
                $value = $this->env[$envVar];
            }
            if (is_string($value) && $value !== '') {
                if (!is_dir($value)) {
                    throw new \RuntimeException(
                        sprintf('Invalid environment variable %s: %s (not a directory)', $envVar, $value)
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
    public function getUserConfigDir($absolute = true)
    {
        $path = $this->get('application.user_config_dir');

        return $absolute ? $this->getHomeDirectory() . DIRECTORY_SEPARATOR . $path : $path;
    }

    /**
     * @return \Platformsh\Cli\Service\Filesystem
     */
    private function fs()
    {
        return $this->fs ?: new Filesystem();
    }

    /**
     * Returns a directory where user-specific files can be written.
     *
     * This may be for storing state, logs, credentials, etc.
     *
     * @return string
     */
    public function getWritableUserDir()
    {
        $path = isset($this->config['application']['writable_user_dir'])
            ? $this->config['application']['writable_user_dir']
            : $this->getUserConfigDir(false);
        $configDir = $this->getHomeDirectory() . DIRECTORY_SEPARATOR . $path;

        // If the directory is not writable (e.g. if we are on a Platform.sh
        // environment), use a temporary directory instead.
        if (!$this->fs()->canWrite($configDir) || (file_exists($configDir) && !is_dir($configDir))) {
            return sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->get('application.tmp_sub_dir');
        }

        return $configDir;
    }

    /**
     * @param bool $subDir
     *
     * @return string
     */
    public function getSessionDir($subDir = false)
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
    public function getSessionId()
    {
        return $this->getWithDefault('api.session_id', 'default');
    }

    /**
     * @param string $prefix
     *
     * @return string
     */
    public function getSessionIdSlug($prefix = 'sess-cli-')
    {
        return $prefix . preg_replace('/[^\w\-]+/', '-', $this->getSessionId());
    }

    /**
     * Sets a new session ID.
     *
     * @param string $id
     * @param bool   $persist
     */
    public function setSessionId($id, $persist = false)
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
    public function isSessionIdFromEnv()
    {
        $sessionId = $this->getSessionId();
        return $sessionId !== 'default' && $sessionId === $this->getEnv('SESSION_ID');
    }

    /**
     * Returns the path to a file where the session ID is saved.
     *
     * @return string
     */
    private function getSessionIdFile()
    {
        return $this->getWritableUserDir() . DIRECTORY_SEPARATOR . 'session-id';
    }

    /**
     * Validates a user-provided session ID.
     *
     * @param string $id
     */
    public function validateSessionId($id)
    {
        if (strpos($id, 'api-token-') === 0 || !\preg_match('@^[a-z0-9_-]+$@i', $id)) {
            throw new \InvalidArgumentException('Invalid session ID: ' . $id);
        }
    }

    /**
     * Returns a new Config instance with overridden values.
     *
     * @param array $overrides
     *
     * @return self
     */
    public function withOverrides(array $overrides)
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
     * @return array
     */
    private function loadConfigFromFile($filename)
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

    private function applyEnvironmentOverrides()
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
    }

    /**
     * Get an environment variable
     *
     * @param string $name
     *   The variable name.
     * @param bool $addPrefix
     *   Whether to add the configured prefix to the variable name.
     *
     * @return mixed|false
     *   The value of the environment variable, or false if it is not set.
     */
    private function getEnv($name, $addPrefix = true)
    {
        $prefix = $addPrefix && isset($this->config['application']['env_prefix']) ? $this->config['application']['env_prefix'] : '';
        if (array_key_exists($prefix . $name, $this->env)) {
            return $this->env[$prefix . $name];
        }

        return getenv($prefix . $name);
    }

    private function applyUserConfigOverrides()
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
     * Test if an experiment (a feature flag) is enabled.
     *
     * @param string $name
     *
     * @return bool
     */
    public function isExperimentEnabled($name)
    {
        return !empty($this->config['experimental']['all_experiments']) || !empty($this->config['experimental'][$name]);
    }

    /**
     * Test if a command should be hidden.
     *
     * @param string $name
     *
     * @return bool
     */
    public function isCommandHidden($name)
    {
        return (!empty($this->config['application']['hidden_commands'])
            && in_array($name, $this->config['application']['hidden_commands']));
    }

    /**
     * Test if a command should be enabled.
     *
     * @param string $name
     *
     * @return bool
     */
    public function isCommandEnabled($name)
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
     *
     * @return string
     */
    public function getVersion() {
        if (isset($this->version)) {
            return $this->version;
        }
        $version = $this->getWithDefault('application.version', '@version-placeholder@');
        if (substr($version, 0, 1) === '@' && substr($version, -1) === '@') {
            // Silently try getting the version from Git.
            $tag = (new Shell())->execute(['git', 'describe', '--tags'], CLI_ROOT);
            if ($tag !== false && substr($tag, 0, 1) === 'v') {
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
    public function getUserAgent()
    {
        $template = $this->getWithDefault(
            'api.user_agent',
            '{APP_NAME_DASH}/{VERSION} ({UNAME_S}; {UNAME_R}; PHP {PHP_VERSION})'
        );
        $replacements = [
            '{APP_NAME_DASH}' => \str_replace(' ', '-', $this->get('application.name')),
            '{APP_NAME}' => $this->get('application.name'),
            '{APP_SLUG}' => $this->get('application.slug'),
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
     * @return array
     *   An ordered array of proxy URLs keyed by scheme: 'https' and/or 'http'.
     */
    public function getProxies() {
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
     * @param int|float|null $timeout
     *
     * @return array
     */
    public function getStreamContextOptions($timeout = null)
    {
        $opts = [
            // See https://www.php.net/manual/en/context.http.php
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout !== null ? $timeout : $this->getWithDefault('api.default_timeout', 30),
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
        if ($this->getWithDefault('api.skip_ssl', false)) {
            $opts['ssl']['verify_peer'] = false;
            $opts['ssl']['verify_peer_name'] = false;
        } else {
            $caBundlePath = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
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
    public function isWrapped()
    {
        return getenv($this->get('application.env_prefix') . 'WRAPPED') === '1';
    }

    /**
     * Returns all the current configuration.
     *
     * @return array
     */
    public function getAll()
    {
        return $this->config;
    }

    /**
     * Applies defaults values based on other config values.
     */
    private function applyDynamicDefaults()
    {
        $this->applyUrlDefaults();
        $this->applyLocalDirectoryDefaults();

        if (!isset($this->config['application']['slug'])) {
            $this->config['application']['slug'] = preg_replace('/[^a-z0-9-]+/', '-', str_replace(['.', ' '], ['', '-'], strtolower($this->get('application.name'))));
        }
        if (!isset($this->config['application']['tmp_sub_dir'])) {
            $this->config['application']['tmp_sub_dir'] = $this->get('application.slug') . '-tmp';
        }
        if (!isset($this->config['api']['oauth2_client_id'])) {
            $this->config['api']['oauth2_client_id'] = $this->get('application.slug');
        }
        if (!isset($this->config['detection']['console_domain']) && isset($this->config['service']['console_url'])) {
            $consoleDomain = parse_url($this->config['service']['console_url'], PHP_URL_HOST);
            if ($consoleDomain !== false) {
                $this->config['detection']['console_domain'] = $consoleDomain;
            }
        }
        if (!isset($this->config['service']['applications_config_file'])) {
            $this->config['service']['applications_config_file'] = $this->get('service.project_config_dir') . '/applications.yaml';
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

    private function applyUrlDefaults()
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
                $this->config['api'][$apiSubKey] = rtrim($authUrl, '/') . $path;
            }
        }
    }

    private function applyLocalDirectoryDefaults()
    {
        if (isset($this->config['local']['local_dir'])) {
            $localDir = $this->config['local']['local_dir'];
        } else {
            $localDir = $this->get('service.project_config_dir') . DIRECTORY_SEPARATOR . 'local';
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
    public function getApiUrl()
    {
        return (string) $this->get('api.base_url');
    }
}
