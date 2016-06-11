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
            $this->applyEnvironmentOverrides();
            $this->applyUserConfigOverrides();
        }
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function has($name)
    {
        Util::getNestedArrayValue(self::$config, explode('.', $name), $exists);

        return $exists;
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
            'API_TOKEN' => 'api.permanent_access_token', // Deprecated
            'COPY_ON_WINDOWS' => 'local.copy_on_windows',
            'DEBUG' => 'api.debug',
            'DISABLE_CACHE' => 'api.disable_cache',
            'DRUSH' => 'local.drush_executable',
            'SESSION_ID' => 'api.session_id',
            'SKIP_SSL' => 'api.skip_ssl',
            'ACCOUNTS_API' => 'api.accounts_api_url',
        ];

        $prefix = isset(self::$config['application']['env_prefix']) ? self::$config['application']['env_prefix'] : '';
        foreach ($overrideMap as $var => $key) {
            if (array_key_exists($prefix . $var, $this->env)) {
                Util::setNestedArrayValue(self::$config, explode('.', $key), $this->env[$prefix . $var], true);
            }
        }
    }

    /**
     * @return array
     */
    public function getUserConfig()
    {
        $userConfig = [];
        $userConfigFile = $this->getUserConfigDir() . '/config.yaml';
        if (file_exists($userConfigFile)) {
            $userConfig = $this->loadConfigFromFile($userConfigFile);
        }

        return $userConfig;
    }

    /**
     * @param array $config
     */
    public function writeUserConfig(array $config)
    {
        $dir = $this->getUserConfigDir();
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            trigger_error('Failed to create user config directory: ' . $dir, E_USER_WARNING);
        }
        $configFile = $dir . '/config.yaml';
        if (file_put_contents($configFile, Yaml::dump($config, 10)) === false) {
            trigger_error('Failed to write user config to: ' . $configFile, E_USER_WARNING);
        }
    }

    protected function applyUserConfigOverrides()
    {
        // A whitelist of allowed overrides.
        $overrideMap = [
            'experimental' => 'experimental',
        ];

        $userConfig = $this->getUserConfig();
        if (!empty($userConfig)) {
            foreach ($overrideMap as $userConfigKey => $configKey) {
                $value = Util::getNestedArrayValue($userConfig, explode('.', $userConfigKey), $exists);
                if ($exists) {
                    $configParents = explode('.', $configKey);
                    $default = Util::getNestedArrayValue(self::$config, $configParents, $defaultExists);
                    if ($defaultExists && is_array($default)) {
                        $value = array_merge_recursive($default, $value);
                    }
                    Util::setNestedArrayValue(self::$config, $configParents, $value, true);
                }
            }
        }
    }
}
