<?php

declare(strict_types=1);

namespace Platformsh\Cli\Local;

use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Local\BuildFlavor\Symfony;
use Platformsh\Cli\Local\BuildFlavor\Composer;
use Platformsh\Cli\Local\BuildFlavor\NodeJs;
use Platformsh\Cli\Local\BuildFlavor\NoBuildFlavor;
use Symfony\Component\Yaml\Exception\ParseException;
use Platformsh\Cli\Model\AppConfig;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Exception\InvalidConfigException;
use Platformsh\Cli\Local\BuildFlavor\BuildFlavorInterface;
use Platformsh\Cli\Service\Mount;
use Platformsh\Cli\Util\YamlParser;

/**
 * Represents an application stored locally inside a source directory.
 *
 * Normally instantiated via ApplicationFinder::findApplications().
 *
 * @see ApplicationFinder::findApplications()
 */
class LocalApplication
{
    protected string $appRoot;
    protected string $sourceDir;
    protected Config $cliConfig;
    protected Mount $mount;

    private bool $single = false;

    /**
     * @param string      $appRoot
     * @param Config|null $cliConfig
     * @param string|null $sourceDir
     * @param AppConfig|null $config
     */
    public function __construct(string $appRoot, ?Config $cliConfig = null, ?string $sourceDir = null, protected ?AppConfig $config = null)
    {
        if (!is_dir($appRoot)) {
            throw new \InvalidArgumentException("Application directory not found: $appRoot");
        }
        $this->cliConfig = $cliConfig ?: new Config();
        $this->appRoot = $appRoot;
        $this->sourceDir = $sourceDir ?: $appRoot;
        $this->mount = new Mount();
    }

    /**
     * Get a unique identifier for this app.
     *
     * @return string
     */
    public function getId(): string
    {
        return ($this->getName() ?: $this->getPath()) ?: 'default';
    }

    /**
     * Returns the type of the app.
     */
    public function getType(): ?string
    {
        $config = $this->getConfig();

        return $config['type'] ?? null;
    }

    /**
     * Returns whether this application is the only one in the project.
     *
     * @return bool
     */
    public function isSingle(): bool
    {
        return $this->single;
    }

    /**
     * Sets that this is is the only application in the project.
     */
    public function setSingle(bool $single = true): void
    {
        $this->single = $single;
    }

    /**
     * Gets the source directory where the application was found.
     *
     * In a single-app project, this is usually the project root.
     */
    public function getSourceDir(): string
    {
        return $this->sourceDir;
    }

    protected function getPath(): string
    {
        return str_replace($this->sourceDir . '/', '', $this->appRoot);
    }

    public function getName(): ?string
    {
        $config = $this->getConfig();

        return !empty($config['name']) ? $config['name'] : null;
    }

    public function getRoot(): string
    {
        return $this->appRoot;
    }

    /**
     * Finds the absolute path to the local web root of this app.
     */
    public function getLocalWebRoot(?string $destination = null): string
    {
        $destination = $destination ?: $this->getSourceDir() . '/' . $this->cliConfig->getStr('local.web_root');
        if ($this->isSingle()) {
            return $destination;
        }

        return $destination . '/' . str_replace('/', '-', $this->getId());
    }

    /**
     * Get the application's configuration, parsed from its YAML definition.
     *
     * @return array<string, mixed>
     * @throws \Exception
     */
    public function getConfig(): array
    {
        return $this->getConfigObject()->getNormalized();
    }

    /**
     * Get the application's configuration as an object.
     *
     * @throws InvalidConfigException if config is not found or invalid
     * @throws ParseException if config cannot be parsed
     * @throws \Exception if the config file cannot be read
     */
    private function getConfigObject(): AppConfig
    {
        if (!isset($this->config)) {
            if ($this->cliConfig->has('service.app_config_file')) {
                $file = $this->appRoot . '/' . $this->cliConfig->getStr('service.app_config_file');
                if (!file_exists($file)) {
                    throw new InvalidConfigException('Configuration file not found: ' . $file);
                }
                $config = (array) (new YamlParser())->parseFile($file);
                $this->config = new AppConfig($config);
            } else {
                $this->config = new AppConfig([]);
            }
        }

        return $this->config;
    }

    /**
     * Gets a list of shared file mounts configured for the app.
     *
     * @return array<string, string>
     */
    public function getSharedFileMounts(): array
    {
        $config = $this->getConfig();

        return !empty($config['mounts'])
            ? $this->mount->getSharedFileMounts($config['mounts'])
            : [];
    }

    /**
     * @return BuildFlavorInterface[]
     */
    public function getBuildFlavors(): array
    {
        return [
            new Drupal(),
            new Symfony(),
            new Composer(),
            new NodeJs(),
            new NoBuildFlavor(),
        ];
    }

    /**
     * Get the build flavor for the application.
     *
     * @throws InvalidConfigException If a build flavor is not found.
     */
    public function getBuildFlavor(): BuildFlavorInterface
    {
        $appConfig = $this->getConfig();
        if (!isset($appConfig['type'])) {
            throw new InvalidConfigException('Application configuration key not found: `type`');
        }

        $key = $appConfig['build']['flavor'] ?? 'default';
        [$stack, ] = explode(':', (string) $appConfig['type'], 2);
        foreach (self::getBuildFlavors() as $candidate) {
            if (in_array($key, $candidate->getKeys())
                && ($candidate->getStacks() === [] || in_array($stack, $candidate->getStacks()))) {
                return $candidate;
            }
        }
        throw new InvalidConfigException('Build flavor not found: ' . $key);
    }

    /**
     * Finds the configured document root for the application, as a relative path.
     */
    public function getDocumentRoot(string $default = 'public'): string
    {
        return $this->getConfigObject()->getDocumentRoot() ?: $default;
    }

    /**
     * Checks whether the whole app should be moved into the document root.
     */
    public function shouldMoveToRoot(): bool
    {
        $config = $this->getConfig();

        if (isset($config['move_to_root']) && $config['move_to_root'] === true) {
            return true;
        }

        return $this->getDocumentRoot() === 'public' && !is_dir($this->getRoot() . '/public');
    }
}
