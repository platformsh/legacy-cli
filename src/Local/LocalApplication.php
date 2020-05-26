<?php
namespace Platformsh\Cli\Local;

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

    protected $appRoot;
    protected $config;
    protected $sourceDir;
    protected $cliConfig;
    protected $mount;

    private $single = false;

    /**
     * @param string      $appRoot
     * @param Config|null $cliConfig
     * @param string|null $sourceDir
     * @param AppConfig|null $appConfig
     */
    public function __construct($appRoot, Config $cliConfig = null, $sourceDir = null, AppConfig $appConfig = null)
    {
        if (!is_dir($appRoot)) {
            throw new \InvalidArgumentException("Application directory not found: $appRoot");
        }
        $this->cliConfig = $cliConfig ?: new Config();
        $this->appRoot = $appRoot;
        $this->sourceDir = $sourceDir ?: $appRoot;
        $this->mount = new Mount();
        $this->config = $appConfig;
    }

    /**
     * Get a unique identifier for this app.
     *
     * @return string
     */
    public function getId()
    {
        return $this->getName() ?: $this->getPath() ?: 'default';
    }

    /**
     * Returns the type of the app.
     *
     * @return string|null
     */
    public function getType()
    {
        $config = $this->getConfig();

        return isset($config['type']) ? $config['type'] : null;
    }

    /**
     * Returns whether this application is the only one in the project.
     *
     * @return bool
     */
    public function isSingle()
    {
       return $this->single;
    }

    /**
     * Set that this is is the only application in the project.
     *
     * @param bool $single
     */
    public function setSingle($single = true)
    {
        $this->single = $single;
    }

    /**
     * Get the source directory where the application was found.
     *
     * In a single-app project, this is usually the project root.
     *
     * @return string
     */
    public function getSourceDir()
    {
        return $this->sourceDir;
    }

    /**
     * @return string
     */
    protected function getPath()
    {
        return str_replace($this->sourceDir . '/', '', $this->appRoot);
    }

    /**
     * @return string|null
     */
    public function getName()
    {
        $config = $this->getConfig();

        return !empty($config['name']) ? $config['name'] : null;
    }

    /**
     * @return string
     */
    public function getRoot()
    {
        return $this->appRoot;
    }

    /**
     * Get the absolute path to the local web root of this app.
     *
     * @param string|null $destination
     *
     * @return string
     */
    public function getLocalWebRoot($destination = null)
    {
        $destination = $destination ?: $this->getSourceDir() . '/' . $this->cliConfig->get('local.web_root');
        if ($this->isSingle()) {
            return $destination;
        }

        return $destination . '/' . str_replace('/', '-', $this->getId());
    }

    /**
     * Get the application's configuration, parsed from its YAML definition.
     *
     * @return array
     * @throws \Exception
     */
    public function getConfig()
    {
        return $this->getConfigObject()->getNormalized();
    }

    /**
     * Get the application's configuration as an object.
     *
     * @throws InvalidConfigException if config is not found or invalid
     * @throws \Symfony\Component\Yaml\Exception\ParseException if config cannot be parsed
     * @throws \Exception if the config file cannot be read
     *
     * @return AppConfig
     */
    private function getConfigObject()
    {
        if (!isset($this->config)) {
            $file = $this->appRoot . '/' . $this->cliConfig->get('service.app_config_file');
            if (!file_exists($file)) {
                throw new InvalidConfigException('Configuration file not found: ' . $file);
            }
            $config = (array) (new YamlParser())->parseFile($file);
            $this->config = new AppConfig($config);
        }

        return $this->config;
    }

    /**
     * Get a list of shared file mounts configured for the app.
     *
     * @return array
     */
    public function getSharedFileMounts()
    {
        $config = $this->getConfig();

        return !empty($config['mounts'])
            ? $this->mount->getSharedFileMounts($config['mounts'])
            : [];
    }

    /**
     * @return BuildFlavorInterface[]
     */
    public function getBuildFlavors()
    {
        return [
            new BuildFlavor\Drupal(),
            new BuildFlavor\Symfony(),
            new BuildFlavor\Composer(),
            new BuildFlavor\NodeJs(),
            new BuildFlavor\NoBuildFlavor(),
        ];
    }

    /**
     * Get the build flavor for the application.
     *
     * @throws InvalidConfigException If a build flavor is not found.
     *
     * @return BuildFlavorInterface
     */
    public function getBuildFlavor()
    {
        $appConfig = $this->getConfig();
        if (!isset($appConfig['type'])) {
            throw new InvalidConfigException('Application configuration key not found: `type`');
        }

        $key = isset($appConfig['build']['flavor']) ? $appConfig['build']['flavor'] : 'default';
        list($stack, ) = explode(':', $appConfig['type'], 2);
        foreach (self::getBuildFlavors() as $candidate) {
            if (in_array($key, $candidate->getKeys())
                && ($candidate->getStacks() === [] || in_array($stack, $candidate->getStacks()))) {
                return $candidate;
            }
        }
        throw new InvalidConfigException('Build flavor not found: ' . $key);
    }

    /**
     * Get the configured document root for the application, as a relative path.
     *
     * @param string $default
     *
     * @todo stop using 'public' as the default
     *
     * @return string
     */
    public function getDocumentRoot($default = 'public')
    {
        return $this->getConfigObject()->getDocumentRoot() ?: $default;
    }

    /**
     * Check whether the whole app should be moved into the document root.
     *
     * @return string
     */
    public function shouldMoveToRoot()
    {
        $config = $this->getConfig();

        if (isset($config['move_to_root']) && $config['move_to_root'] === true) {
            return true;
        }

        return $this->getDocumentRoot() === 'public' && !is_dir($this->getRoot() . '/public');
    }
}
