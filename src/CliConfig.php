<?php

namespace Platformsh\Cli;

use Platformsh\Cli\Util\Util;
use Symfony\Component\Yaml\Yaml;

/**
 * Configuration used throughout the CLI.
 */
class CliConfig
{
    /** @var array */
    protected static $config = [];

    /**
     * @param array|null $env
     * @param string     $defaultsFile
     * @param bool       $reset
     */
    public function __construct(array $env = null, $defaultsFile = CLI_ROOT . '/config.yaml', $reset = false)
    {
        if (empty(self::$config) || $reset) {
            $defaults = Yaml::parse(file_get_contents($defaultsFile));
            self::$config = $this->resolve(
                $defaults,
                $env !== null ? $env : $_ENV
            );
        }
    }

    public function has($name)
    {
        Util::getNestedArrayValue(self::$config, explode('.', $name), $exists);

        return $exists;
    }

    public function get($name)
    {
        $value = Util::getNestedArrayValue(self::$config, explode('.', $name), $exists);
        if (!$exists) {
            throw new \RuntimeException('Configuration not defined: ' . $name);
        }

        return $value;
    }

    /**
     * Combine the default configuration with environment variables.
     *
     * @param array $defaults
     * @param array $env
     *
     * @return array
     */
    protected function resolve(array $defaults = [], array $env = [])
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
        $config = $defaults;
        $prefix = isset($config['application']['env_prefix']) ? $config['application']['env_prefix'] : '';
        foreach ($overrideMap as $var => $key) {
            if (array_key_exists($prefix . $var, $env)) {
                Util::setNestedArrayValue($config, explode('.', $key), $env[$prefix . $var], true);
            }
        }

        return $config;
    }
}
