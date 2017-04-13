<?php
namespace Platformsh\Cli\Local;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Exception\InvalidConfigException;
use Platformsh\Cli\Local\BuildFlavor\BuildFlavorInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;

class LocalApplication
{

    protected $appRoot;
    protected $config;
    protected $sourceDir;
    protected $cliConfig;

    /**
     * @param string         $appRoot
     * @param Config|null $cliConfig
     * @param string|null    $sourceDir
     */
    public function __construct($appRoot, Config $cliConfig = null, $sourceDir = null)
    {
        if (!is_dir($appRoot)) {
            throw new \InvalidArgumentException("Application directory not found: $appRoot");
        }
        $this->cliConfig = $cliConfig ?: new Config();
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
     * Get the destination relative path for the web root of this application.
     *
     * @return string
     */
    public function getWebPath()
    {
        return str_replace('/', '-', $this->getId());
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
            $file = $this->appRoot . '/' . $this->cliConfig->get('service.app_config_file');
            if (file_exists($file)) {
                try {
                    $parser = new Parser();
                    $config = (array) $parser->parse(file_get_contents($file));
                    $this->config = $this->normalizeConfig($config);
                } catch (ParseException $e) {
                    throw new InvalidConfigException(
                        "Parse error in file '$file': \n" . $e->getMessage()
                    );
                }
            }
        }

        return $this->config;
    }

    /**
     * Get a list of shared file mounts configured for the app.
     *
     * @return array
     *     An array of shared file mount paths, keyed by the path in the app.
     *     Leading and trailing slashes are stripped.
     */
    public function getSharedFileMounts()
    {
        $sharedFileMounts = [];
        $appConfig = $this->getConfig();
        if (!empty($appConfig['mounts'])) {
            foreach ($appConfig['mounts'] as $path => $uri) {
                if (preg_match('#^shared:files/(.+)$#', $uri, $matches)) {
                    $sharedFileMounts[trim($path, '/')] = trim($matches[1], '/');
                }
            }
        }

        return $sharedFileMounts;
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
        // Backwards compatibility with old config format: `toolstack` is
        // changed to application `type` and `build`.`flavor`.
        if (isset($config['toolstack'])) {
            if (!strpos($config['toolstack'], ':')) {
                throw new InvalidConfigException("Invalid value for 'toolstack'");
            }
            list($config['type'], $config['build']['flavor']) = explode(':', $config['toolstack'], 2);
        }

        // The `web` section has changed to `web`.`locations`.
        if (isset($config['web']['document_root']) && !isset($config['web']['locations'])) {
            $oldConfig = $config['web'] + $this->getOldWebDefaults();

            $location = &$config['web']['locations']['/'];

            $location['root'] = $oldConfig['document_root'];
            $location['expires'] = $oldConfig['expires'];
            $location['passthru'] = $oldConfig['passthru'];
            $location['allow'] = true;

            foreach ($oldConfig['whitelist'] as $pattern) {
                $location['allow'] = false;
                $location['rules'][$pattern]['allow'] = true;
            }

            foreach ($oldConfig['blacklist'] as $pattern) {
                $location['rules'][$pattern]['allow'] = false;
            }
        }

        return $config;
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
     * Get a list of applications in a directory.
     *
     * @param string $directory
     *     The absolute path to a directory.
     * @param Config|null $config
     *     CLI configuration.
     *
     * @return LocalApplication[]
     */
    public static function getApplications($directory, Config $config = null)
    {
        // Finder can be extremely slow with a deep directory structure. The
        // search depth is limited to safeguard against this.
        $finder = new Finder();
        $config = $config ?: new Config();
        $finder->in($directory)
               ->ignoreDotFiles(false)
               ->name($config->get('service.app_config_file'))
               ->notPath('builds')
               ->notPath($config->get('local.local_dir'))
               ->ignoreUnreadableDirs()
               ->depth('> 0')
               ->depth('< 5');

        $applications = [];
        if ($finder->count() == 0) {
            $applications[$directory] = new LocalApplication($directory, $config, $directory);
        } else {
            /** @var \Symfony\Component\Finder\SplFileInfo $file */
            foreach ($finder as $file) {
                $appRoot = dirname($file->getRealPath());
                $applications[$appRoot] = new LocalApplication($appRoot, $config, $directory);
            }
        }

        return $applications;
    }

    /**
     * Get a single application by name.
     *
     * @param string|null $name
     *     The application name.
     * @param string $directory
     *     The absolute path to a directory.
     * @param Config|null $config
     *     CLI configuration.
     *
     * @return LocalApplication
     */
    public static function getApplication($name, $directory, Config $config = null)
    {
        $apps = self::getApplications($directory, $config);
        if ($name === null && count($apps) === 1) {
            return reset($apps);
        }
        foreach ($apps as $app) {
            if ($app->getName() === $name) {
                return $app;
            }
        }

        throw new \InvalidArgumentException('App not found: ' . $name);
    }

    /**
     * Get the configured document root for the application, as a relative path.
     *
     * @return string
     */
    public function getDocumentRoot()
    {
        $config = $this->getConfig();

        // The default document root is '/public'. This is used if the root is
        // not set, if it is empty, or if it is set to '/'.
        $documentRoot = '/public';
        if (!empty($config['web']['locations']['/']['root']) && $config['web']['locations']['/']['root'] !== '/') {
            $documentRoot = $config['web']['locations']['/']['root'];
        }

        return ltrim($documentRoot, '/');
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

    /**
     * @return array
     */
    protected function getOldWebDefaults()
    {
        return [
            'document_root' => '/public',
            'expires' => 0,
            'passthru' => '/index.php',
            'blacklist' => [],
            'whitelist' => [
                // CSS and Javascript.
                '\.css$',
                '\.js$',

                // image/* types.
                '\.gif$',
                '\.jpe?g$',
                '\.png$',
                '\.tiff?$',
                '\.wbmp$',
                '\.ico$',
                '\.jng$',
                '\.bmp$',
                '\.svgz?$',

                // audio/* types.
                '\.midi?$',
                '\.mpe?ga$',
                '\.mp2$',
                '\.mp3$',
                '\.m4a$',
                '\.ra$',
                '\.weba$',

                // video/* types.
                '\.3gpp?$',
                '\.mp4$',
                '\.mpe?g$',
                '\.mpe$',
                '\.ogv$',
                '\.mov$',
                '\.webm$',
                '\.flv$',
                '\.mng$',
                '\.asx$',
                '\.asf$',
                '\.wmv$',
                '\.avi$',

                // application/ogg.
                '\.ogx$',

                // application/x-shockwave-flash.
                '\.swf$',

                // application/java-archive.
                '\.jar$',

                // fonts types.
                '\.ttf$',
                '\.eot$',
                '\.woff$',
                '\.otf$',

                // robots.txt.
                '/robots\.txt$',
            ],
        ];
    }
}
