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
    private $defaultsFile;
    private $env;
    private $fs;
    private $version;
    private $homeDir;

    /**
     * @param array|null  $env
     * @param string|null $defaultsFile
     */
    public function __construct(array $env = null, $defaultsFile = null)
    {
        $this->env = $env !== null ? $env : $this->getDefaultEnv();

        $this->defaultsFile = $defaultsFile ?: CLI_ROOT . '/config.yaml';
        $this->config = $this->loadConfigFromFile($this->defaultsFile);

        // Load the session ID from a file.
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

        $this->applyUserConfigOverrides();
        $this->applyEnvironmentOverrides();

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
     * @param mixed  $default
     *
     * @return mixed
     */
    public function getWithDefault($name, $default)
    {
        $value = NestedArrayUtil::getNestedArrayValue($this->config, explode('.', $name), $exists);
        if (!$exists) {
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

        throw new \RuntimeException(sprintf('Could not determine home directory. Set the %s environment variable.', $prefix . 'HOME'));
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
     * @return string
     */
    public function getWritableUserDir()
    {
        $path = $this->get('application.writable_user_dir');
        $configDir = $this->getHomeDirectory() . DIRECTORY_SEPARATOR . $path;

        // If the config directory is not writable (e.g. if we are on a
        // Platform.sh environment), use a temporary directory instead.
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
        return $this->get('api.session_id') ?: 'default';
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
        return $this->getEnv('SESSION_ID') === $this->config['api']['session_id'];
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
        $config = new self($this->env, $this->defaultsFile);
        foreach ($overrides as $key => $value) {
            NestedArrayUtil::setNestedArrayValue($config->config, explode('.', $key), $value);
        }

        return $config;
    }

    /**
     * @param string $filename
     *
     * @return array
     */
    private function loadConfigFromFile($filename)
    {
        $contents = file_get_contents($filename);
        if ($contents === false) {
            throw new \RuntimeException('Failed to read config file: ' . $filename);
        }

        return (array) Yaml::parse($contents);
    }

    private function applyEnvironmentOverrides()
    {
        $overrideMap = [
            'TOKEN' => 'api.token',
            'API_TOKEN' => 'api.access_token', // Deprecated
            'COPY_ON_WINDOWS' => 'local.copy_on_windows',
            'DEBUG' => 'api.debug',
            'DISABLE_CACHE' => 'api.disable_cache',
            'DRUSH' => 'local.drush_executable',
            'SESSION_ID' => 'api.session_id',
            'SKIP_SSL' => 'api.skip_ssl',
            'ACCOUNTS_API' => 'api.accounts_api_url',
            'API_URL' => 'api.base_url',
            'DEFAULT_TIMEOUT' => 'api.default_timeout',
            'OAUTH2_AUTH_URL' => 'api.oauth2_auth_url',
            'OAUTH2_CLIENT_ID' => 'api.oauth2_client_id',
            'OAUTH2_TOKEN_URL' => 'api.oauth2_token_url',
            'OAUTH2_REVOKE_URL' => 'api.oauth2_revoke_url',
            'CERTIFIER_URL' => 'api.certifier_url',
            'AUTO_LOAD_SSH_CERT' => 'api.auto_load_ssh_cert',
            'UPDATES_CHECK' => 'updates.check',
            'USER_AGENT' => 'api.user_agent',
        ];

        foreach ($overrideMap as $var => $key) {
            $value = $this->getEnv($var);
            if ($value !== false) {
                NestedArrayUtil::setNestedArrayValue($this->config, explode('.', $key), $value, true);
            }
        }

        // Special case: replace the list api.ssh_domain_wildcards with the value of {PREFIX}SSH_DOMAIN_WILDCARD.
        if (($value = $this->getEnv('SSH_DOMAIN_WILDCARD')) !== false) {
            $this->config['api']['ssh_domain_wildcards'] = [$value];
        }
    }

    /**
     * Get an environment variable
     *
     * @param string $name
     *   The variable name. The configured prefix will be prepended.
     *
     * @return mixed|false
     *   The value of the environment variable, or false if it is not set.
     */
    private function getEnv($name)
    {
        $prefix = isset($this->config['application']['env_prefix']) ? $this->config['application']['env_prefix'] : '';
        if (array_key_exists($prefix . $name, $this->env)) {
            return $this->env[$prefix . $name];
        }

        return getenv($prefix . $name);
    }

    /**
     * @return array
     */
    private function getUserConfig()
    {
        $userConfigFile = $this->getUserConfigDir() . '/config.yaml';
        if (file_exists($userConfigFile)) {
            return $this->loadConfigFromFile($userConfigFile);
        }

        return [];
    }

    private function applyUserConfigOverrides()
    {
        // A list of allowed overrides.
        $overrideMap = [
            'api' => 'api',
            'local.copy_on_windows' => 'local.copy_on_windows',
            'local.drush_executable' => 'local.drush_executable',
            'experimental' => 'experimental',
            'updates' => 'updates',
            'application.login_method' => 'application.login_method',
            'application.writable_user_dir' => 'application.writable_user_dir',
            'application.date_format' => 'application.date_format',
            'application.timezone' => 'application.timezone',
        ];

        $userConfig = $this->getUserConfig();
        if (!empty($userConfig)) {
            foreach ($overrideMap as $userConfigKey => $configKey) {
                $value = NestedArrayUtil::getNestedArrayValue($userConfig, explode('.', $userConfigKey), $exists);
                if ($exists) {
                    $configParents = explode('.', $configKey);
                    $default = NestedArrayUtil::getNestedArrayValue($this->config, $configParents, $defaultExists);
                    if ($defaultExists && is_array($default)) {
                        if (!is_array($value)) {
                            continue;
                        }
                        $value = array_replace_recursive($default, $value);
                    }
                    NestedArrayUtil::setNestedArrayValue($this->config, $configParents, $value, true);
                }
            }
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
        $version = $this->get('application.version');
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
}
