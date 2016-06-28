<?php

namespace Platformsh\Cli;

use Platformsh\Cli\Helper\FilesystemHelper;
use Platformsh\Cli\Util\Util;
use Symfony\Component\Yaml\Yaml;

/**
 * Configuration used throughout the CLI.
 */
class CliConfig
{
    protected static $config = [];

    protected $env = [];

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
            $this->applyUserConfigOverrides();
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
        $value = Util::getNestedArrayValue(self::$config, explode('.', $name), $exists);

        return $exists && (!$notNull || $value !== null);
    }

    /**
     * @param string $name
     *
     * @return mixed
     */
    public function get($name)
    {
        $value = Util::getNestedArrayValue(self::$config, explode('.', $name), $exists);
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
        return FilesystemHelper::getHomeDirectory() . '/' . $this->get('application.user_config_dir');
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

        return Yaml::parse($contents);
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
        ];

        foreach ($overrideMap as $var => $key) {
            $value = $this->getEnv($var);
            if ($value !== false) {
                Util::setNestedArrayValue(self::$config, explode('.', $key), $value, true);
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
     * @return array
     */
    public function getUserConfig()
    {
        if (!isset($this->userConfig)) {
            $this->userConfig = [];
            $userConfigFile = $this->getUserConfigDir() . '/config.yaml';
            if (file_exists($userConfigFile)) {
                $this->userConfig = $this->loadConfigFromFile($userConfigFile);
            }
        }

        return $this->userConfig;
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

    protected function applyUserConfigOverrides()
    {
        // A whitelist of allowed overrides.
        $overrideMap = [
            'api' => 'api',
            'local.copy_on_windows' => 'local.copy_on_windows',
            'local.drush_executable' => 'local.drush_executable',
            'experimental' => 'experimental',
            'updates' => 'updates',
        ];

        $userConfig = $this->getUserConfig();
        if (!empty($userConfig)) {
            foreach ($overrideMap as $userConfigKey => $configKey) {
                $value = Util::getNestedArrayValue($userConfig, explode('.', $userConfigKey), $exists);
                if ($exists) {
                    $configParents = explode('.', $configKey);
                    $default = Util::getNestedArrayValue(self::$config, $configParents, $defaultExists);
                    if ($defaultExists && is_array($default)) {
                        $value = array_replace_recursive($default, $value);
                    }
                    Util::setNestedArrayValue(self::$config, $configParents, $value, true);
                }
            }
        }
    }
}
