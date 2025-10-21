<?php

declare(strict_types=1);

namespace Platformsh\Cli\Local;

use Platformsh\Cli\Exception\InvalidConfigException;
use Platformsh\Cli\Model\AppConfig;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Util\YamlParser;
use Symfony\Component\Finder\Finder;

/**
 * Finds all applications inside a source directory.
 */
readonly class ApplicationFinder
{
    private Config $config;

    public function __construct(?Config $config = null)
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
    public function findApplications(string $directory): array
    {
        $applications = [];

        $finder = $this->findAppConfigFiles($directory);
        if (!$finder) {
            return [];
        }

        // Find applications defined in individual files, e.g.
        // .platform.app.yaml.
        foreach ($finder as $file) {
            $configFile = $file->getRealPath();
            $appConfig = (array) (new YamlParser())->parseFile($configFile);
            $configuredRoot = $this->getExplicitRoot($appConfig, $directory);
            if ($configuredRoot !== null && !\is_dir($configuredRoot)) {
                throw new InvalidConfigException('Directory not found: ' . $configuredRoot, $configFile, 'source.root');
            }
            $appRoot = $configuredRoot !== null ? $configuredRoot : \dirname((string) $configFile);
            $appName = $appConfig['name'] ?? null;
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
     * @param string $directory
     *
     * @return array<string, LocalApplication>
     */
    private function findGroupedApplications(string $directory): array
    {
        $configFile = $directory . DIRECTORY_SEPARATOR . $this->config->getStr('service.applications_config_file');
        if (!\file_exists($configFile)) {
            return [];
        }

        $applications = [];
        $appConfigs = (array) (new YamlParser())->parseFile($configFile);
        foreach ($appConfigs as $key => $appConfig) {
            if (!is_array($appConfig)) {
                $type = gettype($appConfig);
                throw new InvalidConfigException("Application config has invalid type $type: it must be an object", $configFile, $key);
            }
            $appRoot = $this->getExplicitRoot($appConfig, $directory);
            if ($appRoot === null) {
                throw new InvalidConfigException('The "source.root" key is required', $configFile, $key);
            }
            if (!\is_dir($appRoot)) {
                throw new InvalidConfigException('Directory not found: ' . $appRoot, $configFile, $key . '.source.root');
            }
            $appRoot = \realpath($appRoot) ?: $appRoot;
            $appName = $appConfig['name'] ?? null;
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
     * @see https://docs.upsun.com/anchors/fixed/app/multiple/source/root/
     *
     * @param array<string, mixed> $appConfig
     * @param string $sourceDir
     *
     * @return string|null
     */
    private function getExplicitRoot(array $appConfig, string $sourceDir): ?string
    {
        if (!isset($appConfig['source']['root'])) {
            return null;
        }

        return \rtrim($sourceDir . DIRECTORY_SEPARATOR . \ltrim((string) $appConfig['source']['root'], '\\/'), DIRECTORY_SEPARATOR);
    }

    /**
     * Finds application config files using Symfony Finder.
     */
    private function findAppConfigFiles(string $directory): ?Finder
    {
        // Finder can be extremely slow with a deep directory structure. The
        // search depth is limited to safeguard against this.
        $finder = new Finder();
        if (!$this->config->has('service.app_config_file')) {
            return null;
        }
        return $finder->in($directory)
            ->name($this->config->getStr('service.app_config_file'))
            ->ignoreDotFiles(false)
            ->ignoreUnreadableDirs()
            ->ignoreVCS(true)
            ->exclude([
                '.idea',
                $this->config->getStr('local.local_dir'),
                'builds',
                'node_modules',
                'vendor',
            ])
            ->depth('< 5');
    }
}
