<?php
namespace Platformsh\Cli\Local;

use Platformsh\Cli\Exception\InvalidConfigException;
use Platformsh\Cli\Local\Toolstack\ToolstackInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;

class LocalApplication
{

    protected $appRoot;
    protected $config;
    protected $sourceDir;

    /**
     * @param string $appRoot
     * @param string $sourceDir
     */
    public function __construct($appRoot, $sourceDir = null)
    {
        if (!is_dir($appRoot)) {
            throw new \InvalidArgumentException("Application directory not found: $appRoot");
        }
        $this->appRoot = $appRoot;
        $this->sourceDir = $sourceDir ?: $appRoot;
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
     * @return string
     */
    protected function getPath()
    {
        return str_replace($this->sourceDir . '/' , '', $this->appRoot);
    }

    /**
     * @return string
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
     * Override the application config.
     *
     * @param array $config
     */
    public function setConfig(array $config)
    {
        $this->config = $config;
    }

    /**
     * Get the application's configuration, parsed from its YAML definition.
     *
     * @return array
     */
    public function getConfig()
    {
        if (!isset($this->config)) {
            $this->config = [];
            if (file_exists($this->appRoot . '/.platform.app.yaml')) {
                try {
                    $parser = new Parser();
                    $config = (array) $parser->parse(file_get_contents($this->appRoot . '/.platform.app.yaml'));
                    $this->config = $this->normalizeConfig($config);
                }
                catch (ParseException $e) {
                    throw new InvalidConfigException(
                        "Parse error in file '{$this->appRoot}/.platform.app.yaml'. \n" . $e->getMessage()
                    );
                }
            }
        }

        return $this->config;
    }

    /**
     * Normalize an application's configuration.
     *
     * @param array $config
     *
     * @return array
     */
    protected function normalizeConfig(array $config)
    {
        // Backwards compatibility with old config format: toolstack is changed
        // to application type and build['flavor'].
        if (isset($config['toolstack'])) {
            if (!strpos($config['toolstack'], ':')) {
                throw new InvalidConfigException("Invalid value for 'toolstack'");
            }
            list($config['type'], $config['build']['flavor']) = explode(':', $config['toolstack'], 2);
        }

        return $config;
    }

    /**
     * @return ToolstackInterface[]
     */
    public function getToolstacks()
    {
        return [
            new Toolstack\Drupal(),
            new Toolstack\Symfony(),
            new Toolstack\Composer(),
            new Toolstack\NodeJs(),
            new Toolstack\NoToolstack(),
        ];
    }

    /**
     * Get the toolstack for the application.
     *
     * @throws \Exception   If a specified toolstack is not found.
     *
     * @return ToolstackInterface|false
     */
    public function getToolstack()
    {
        $toolstackChoice = false;

        // For now, we reconstruct a toolstack string based on the 'type' and
        // 'build.flavor' config keys.
        $appConfig = $this->getConfig();
        if (isset($appConfig['type'])) {
            list($stack, ) = explode(':', $appConfig['type'], 2);
            $flavor = isset($appConfig['build']['flavor']) ? $appConfig['build']['flavor'] : 'default';

            // Toolstack classes for HHVM are the same as PHP.
            if ($stack === 'hhvm') {
                $stack = 'php';
            }

            $toolstackChoice = "$stack:$flavor";

            // Alias php:default to php:composer.
            if ($toolstackChoice === 'php:default') {
                $toolstackChoice = 'php:composer';
            }
        }

        foreach (self::getToolstacks() as $toolstack) {
            $key = $toolstack->getKey();
            if ((!$toolstackChoice && $toolstack->detect($this->getRoot()))
                || ($key && $toolstackChoice === $key)
            ) {
                return $toolstack;
            }
        }
        if ($toolstackChoice) {
            throw new \Exception("Toolstack not found: $toolstackChoice");
        }

        return false;
    }

    /**
     * Get a list of applications in a directory.
     *
     * @param string $directory The absolute path to a directory.
     *
     * @return static[]
     */
    public static function getApplications($directory)
    {
        // Finder can be extremely slow with a deep directory structure. The
        // search depth is limited to safeguard against this.
        $finder = new Finder();
        $finder->in($directory)
               ->ignoreDotFiles(false)
               ->notPath('builds')
               ->name('.platform.app.yaml')
               ->depth('> 0')
               ->depth('< 5');

        $applications = [];
        if ($finder->count() == 0) {
            $applications[$directory] = new LocalApplication($directory, $directory);
        }
        else {
            /** @var \Symfony\Component\Finder\SplFileInfo $file */
            foreach ($finder as $file) {
                $appRoot = dirname($file->getRealPath());
                $applications[$appRoot] = new LocalApplication($appRoot, $directory);
            }
        }

        return $applications;
    }
}
