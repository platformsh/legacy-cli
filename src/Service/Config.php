<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Util\NestedArrayUtil;
use Symfony\Component\Yaml\Yaml;

/**
 * Configuration used throughout the CLI.
 */
class Config
{
    protected static $config = [];

    protected $env = [];

    protected $globalConfig = null;

    protected $userConfig = null;

    /**
     * @param array|null  $env
     * @param string|null $defaultsFile
     * @param bool        $reset
     */
    public function __construct(array $env = null, $defaultsFile = null, $reset = false)
    {
        $this->env = $env !== null ? $env : $_ENV;

        if (empty(self::$config) || $reset) {
            $defaultsFile = $defaultsFile ?: CLI_ROOT . '/config.yaml';
            self::$config = $this->loadConfigFromFile($defaultsFile);
            $this->applyConfigOverrides($this->getGlobalConfig());
            $this->applyConfigOverrides($this->getUserConfig());
            $this->applyEnvironmentOverrides();
        }
    }

    /**
     * @param string $name
     * @param bool   $notNull
     *
     * @return bool
     */
    public function has($name, $notNull = true)
    {
        $value = NestedArrayUtil::getNestedArrayValue(self::$config, explode('.', $name), $exists);

        return $exists && (!$notNull || $value !== null);
    }

    /**
     * @param string $name
     *
     * @return mixed
     */
    public function get($name)
    {
        $value = NestedArrayUtil::getNestedArrayValue(self::$config, explode('.', $name), $exists);
        if (!$exists) {
            throw new \RuntimeException('Configuration not defined: ' . $name);
        }

        return $value;
    }

    /**
     * @return string
     */
    public function getUserConfigDir()
    {
        return Filesystem::getHomeDirectory() . '/' . $this->get('application.user_config_dir');
    }

    /**
     * @param string $filename
     *
     * @return array
     */
    protected function loadConfigFromFile($filename)
    {
        $contents = file_get_contents($filename);
        if ($contents === false) {
            throw new \RuntimeException('Failed to read config file: ' . $filename);
        }

        return (array) Yaml::parse($contents);
    }

    protected function applyEnvironmentOverrides()
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
            'UPDATES_CHECK' => 'updates.check',
        ];

        foreach ($overrideMap as $var => $key) {
            $value = $this->getEnv($var);
            if ($value !== false) {
                NestedArrayUtil::setNestedArrayValue(self::$config, explode('.', $key), $value, true);
            }
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
    protected function getEnv($name)
    {
        $prefix = isset(self::$config['application']['env_prefix']) ? self::$config['application']['env_prefix'] : '';
        if (array_key_exists($prefix . $name, $this->env)) {
            return $this->env[$prefix . $name];
        }

        return getenv($prefix . $name);
    }

    /**
     * Returns user configuration.
     *
     * @return array
     */
    public function getUserConfig()
    {
        if (!isset($this->userConfig)) {
            $this->userConfig = [];
            $userConfigFile = $this->getUserConfigDir()
                . '/'
                . $this->get('application.user_config_file');
            if (file_exists($userConfigFile)) {
                $this->userConfig = $this->loadConfigFromFile($userConfigFile);
            }
        }

        return $this->userConfig;
    }

    /**
     * Returns global (system-level) configuration.
     *
     * @return array
     */
    protected function getGlobalConfig()
    {
        if (!isset($this->globalConfig)) {
            $this->globalConfig = [];
            $candidates = (array) $this->get('application.global_config_files');
            foreach ($candidates as $filename) {
                if (file_exists($filename)) {
                    $this->globalConfig = $this->loadConfigFromFile($filename);
                    break;
                }
            }
        }

        return $this->globalConfig;
    }

    /**
     * Update user configuration.
     *
     * @param array $config
     */
    public function writeUserConfig(array $config)
    {
        $dir = $this->getUserConfigDir();
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            trigger_error('Failed to create user config directory: ' . $dir, E_USER_WARNING);
        }
        $existingConfig = $this->getUserConfig();
        $config = array_replace_recursive($existingConfig, $config);
        $configFile = $dir . '/config.yaml';
        $new = !file_exists($configFile);
        if (file_put_contents($configFile, Yaml::dump($config, 10)) === false) {
            trigger_error('Failed to write user config to: ' . $configFile, E_USER_WARNING);
        }
        // If the config file was newly created, then chmod to be r/w only by
        // the user.
        if ($new) {
            chmod($configFile, 0600);
        }
        $this->userConfig = $config;
    }

    /**
     * Merge in a set of configuration, partially overriding the current state.
     *
     * @param array $config
     */
    protected function applyConfigOverrides(array $config)
    {
        if (empty($config)) {
            return;
        }

        // A whitelist of allowed overrides.
        $overrideMap = [
            'api' => 'api',
            'local.copy_on_windows' => 'local.copy_on_windows',
            'local.drush_executable' => 'local.drush_executable',
            'experimental' => 'experimental',
            'updates' => 'updates',
        ];

        foreach ($overrideMap as $userConfigKey => $configKey) {
            $value = NestedArrayUtil::getNestedArrayValue($config, explode('.', $userConfigKey), $exists);
            if (!$exists) {
                continue;
            }
            $configParents = explode('.', $configKey);
            $default = NestedArrayUtil::getNestedArrayValue(self::$config, $configParents, $defaultExists);
            if ($defaultExists && is_array($default)) {
                if (!is_array($value)) {
                    continue;
                }
                $value = array_replace_recursive($default, $value);
            }
            NestedArrayUtil::setNestedArrayValue(self::$config, $configParents, $value, true);
        }
    }
}
