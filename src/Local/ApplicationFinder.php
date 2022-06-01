<?php

namespace Platformsh\Cli\Local;

use Platformsh\Cli\Exception\InvalidConfigException;
use Platformsh\Cli\Model\AppConfig;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Util\YamlParser;
use Symfony\Component\Finder\Finder;

/**
 * Finds all applications inside a source directory.
 */
class ApplicationFinder
{
    private $config;

    public function __construct(Config $config = null)
    {
        $this->config = $config ?: new Config();
    }

    /**
     * Finds applications in a directory.
     *
     * @param string $directory
     *     The absolute path to a source directory.
     *
     * @return LocalApplication[]
     */
    public function findApplications($directory)
    {
        $applications = [];

        // Find applications defined in individual files, e.g.
        // .platform.app.yaml.
        foreach ($this->findAppConfigFiles($directory) as $file) {
            $configFile = $file->getRealPath();
            $appConfig = (array) (new YamlParser())->parseFile($configFile);
            $configuredRoot = $this->getExplicitRoot($appConfig, $directory);
            if ($configuredRoot !== null && !\is_dir($configuredRoot)) {
                throw new InvalidConfigException('Directory not found: ' . $configuredRoot, $configFile, 'source.root');
            }
            $appRoot = $configuredRoot !== null ? $configuredRoot : \dirname($configFile);
            $appName = isset($appConfig['name']) ? $appConfig['name'] : null;
            if ($appName && isset($applications[$appConfig['name']])) {
                throw new InvalidConfigException(sprintf('An application named %s is already defined', $appConfig['name']), $configFile, 'name');
            }
            $applications[$appName ?: $appRoot] = new LocalApplication($appRoot, $this->config, $directory, new AppConfig($appConfig));
        }

        // Merge with applications defined in a grouped configuration file,
        // e.g. .platform/applications.yaml.
        $applications = \array_merge($applications, $this->findGroupedApplications($directory));

        // If no configured applications were found, treat the entire directory
        // as a single app.
        if (empty($applications)) {
            $applications[$directory] = new LocalApplication($directory, $this->config, $directory);
        }

        // Make it easy to see if an application is the only one in its source
        // directory.
        if (\count($applications) === 1) {
            foreach ($applications as $application) {
                $application->setSingle(true);
                break;
            }
        }

        return \array_values($applications);
    }

    /**
     * Finds applications via the grouped config file, e.g. .platform/applications.yaml.
     *
     * @param $directory
     *
     * @return array
     */
    private function findGroupedApplications($directory)
    {
        $configFile = $directory . DIRECTORY_SEPARATOR . $this->config->get('service.applications_config_file');
        if (!\file_exists($configFile)) {
            return [];
        }

        $applications = [];
        $appConfigs = (array) (new YamlParser())->parseFile($configFile);
        foreach ($appConfigs as $key => $appConfig) {
            $appRoot = $this->getExplicitRoot($appConfig, $directory);
            if ($appRoot === null) {
                throw new InvalidConfigException('The "source.root" key is required', $configFile, $key);
            }
            if (!\is_dir($appRoot)) {
                throw new InvalidConfigException('Directory not found: ' . $appRoot, $configFile, $key . '.source.root');
            }
            $appRoot = \realpath($appRoot) ?: $appRoot;
            $appName = isset($appConfig['name']) ? $appConfig['name'] : null;
            if ($appName && isset($applications[$appName])) {
                throw new InvalidConfigException(sprintf('An application named %s is already defined', $appConfig['name']), $configFile, $key . '.name');
            }
            $applications[$appName ?: $appRoot] = new LocalApplication($appRoot, $this->config, $directory, new AppConfig($appConfig));
        }

        return $applications;
    }

    /**
     * Returns the root directory explicitly configured for the application, if any.
     *
     * @see https://docs.platform.sh/configuration/app/multi-app.html#explicit-sourceroot
     *
     * @param array $appConfig
     * @param string $sourceDir
     *
     * @return string|null
     */
    private function getExplicitRoot(array $appConfig, $sourceDir)
    {
        if (!isset($appConfig['source']['root'])) {
            return null;
        }

        return \rtrim($sourceDir . DIRECTORY_SEPARATOR . \ltrim($appConfig['source']['root'], '\\/'), DIRECTORY_SEPARATOR);
    }

    /**
     * Finds application config files using Symfony Finder.
     *
     * @param string $directory
     *
     * @return Finder
     */
    private function findAppConfigFiles($directory)
    {
        // Finder can be extremely slow with a deep directory structure. The
        // search depth is limited to safeguard against this.
        $finder = new Finder();
        return $finder->in($directory)
            ->name($this->config->get('service.app_config_file'))
            ->ignoreDotFiles(false)
            ->ignoreUnreadableDirs()
            ->ignoreVCS(true)
            ->exclude([
                '.idea',
                $this->config->get('local.local_dir'),
                'builds',
                'node_modules',
                'vendor',
            ])
            ->depth('< 5');
    }
}
