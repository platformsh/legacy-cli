<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Guzzle\Plugin\Oauth2\Oauth2Plugin;
use CommerceGuys\Guzzle\Plugin\Oauth2\GrantType\PasswordCredentials;
use CommerceGuys\Guzzle\Plugin\Oauth2\GrantType\RefreshToken;
use CommerceGuys\Platform\Cli\Api\PlatformClient;
use CommerceGuys\Platform\Cli\Local\LocalProject;
use CommerceGuys\Platform\Cli\Model\Environment;
use CommerceGuys\Platform\Cli\Model\HalResource;
use Doctrine\Common\Cache\FilesystemCache;
use Guzzle\Cache\DoctrineCacheAdapter;
use Guzzle\Plugin\Cache\CachePlugin;
use Guzzle\Plugin\Cache\DefaultCacheStorage;
use Guzzle\Service\Client;
use Guzzle\Service\Description\ServiceDescription;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Dumper;

abstract class PlatformCommand extends Command
{
    protected $config;
    protected $originalConfig;
    protected $deleteConfig;

    protected $oauth2Plugin;
    protected $accountClient;
    protected $platformClient;
    protected $envArgName = 'environment';

    /** @var array */
    protected $project;

    /** @var array */
    protected $environment;

    /**
     * Load configuration from the user's .platform file.
     *
     * Configuration is loaded only if $this->config hasn't been populated
     * already. This allows LoginCommand to avoid writing the config file
     * before using the client for the first time.
     *
     * @todo refactor config / session storage into a separate class
     *
     * @param bool $login
     *
     * @return array|false The configuration array or false on failure.
     */
    public function loadConfig($login = true)
    {
        if (!$this->config) {
            $configPath = $this->getConfigPath();
            if (!file_exists($configPath) && $login) {
                $this->login();
            }
            if (!file_exists($configPath)) {
                return false;
            }
            $yaml = new Parser();
            $this->config = $yaml->parse(file_get_contents($configPath));
            $this->originalConfig = $this->config;
        }

        return $this->config;
    }

    /**
     * Log in the user.
     */
    protected function login() {
        $application = $this->getApplication();
        $command = $application->find('login');
        $input = new ArrayInput(array('command' => 'login'));
        $exitCode = $command->run($input, $application->getOutput());
        if ($exitCode) {
            throw new \Exception('Login failed');
        }
    }

    /**
     * Is this command used to work with your local environment or send
     * commands to the Platform remote environment? Defaults to FALSE.
     */
    public function isLocal()
    {
          return false;
    }

    /**
     * Return an instance of Oauth2Plugin.
     *
     * @return Oauth2Plugin
     */
    protected function getOauth2Plugin()
    {
        if (!$this->oauth2Plugin) {
            $this->loadConfig();
            if (empty($this->config['refresh_token'])) {
                throw new \Exception('Refresh token not found in PlatformCommand::getOauth2Plugin.');
            }

            $oauth2Client = new Client(CLI_ACCOUNTS_SITE . '/oauth2/token');
            $oauth2Client->setDefaultOption('verify', CLI_VERIFY_SSL_CERT);
            $oauth2Client->setUserAgent($this->getUserAgent());
            $config = array(
                'client_id' => 'platform-cli',
            );
            $refreshTokenGrantType = new RefreshToken($oauth2Client, $config);
            $this->oauth2Plugin = new Oauth2Plugin(null, $refreshTokenGrantType);
            $this->oauth2Plugin->setRefreshToken($this->config['refresh_token']);
            if (!empty($this->config['access_token'])) {
                $this->oauth2Plugin->setAccessToken($this->config['access_token']);
            }
        }

        return $this->oauth2Plugin;
    }

    /**
     * Authenticate the user using the given credentials.
     *
     * The credentials are used to acquire a set of tokens (access token
     * and refresh token) that are then stored and used for all future requests.
     * The actual credentials are never stored, there is no need to reuse them
     * since the refresh token never expires.
     *
     * @param string $email The user's email.
     * @param string $password The user's password.
     */
    protected function authenticateUser($email, $password)
    {
        $oauth2Client = new Client(CLI_ACCOUNTS_SITE . '/oauth2/token');
        $oauth2Client->setDefaultOption('verify', CLI_VERIFY_SSL_CERT);
        $oauth2Client->setUserAgent($this->getUserAgent());
        $config = array(
            'username' => $email,
            'password' => $password,
            'client_id' => 'platform-cli',
        );
        $grantType = new PasswordCredentials($oauth2Client, $config);
        $oauth2Plugin = new Oauth2Plugin($grantType);
        $this->config = array(
            'access_token' => $oauth2Plugin->getAccessToken(),
            'refresh_token' => $oauth2Plugin->getRefreshToken(),
        );
    }

    /**
     * Return an instance of the Guzzle client for the Accounts endpoint.
     *
     * @return Client
     */
    protected function getAccountClient()
    {
        if (!$this->accountClient) {
            $description = ServiceDescription::factory(CLI_ROOT . '/services/accounts.php');
            $oauth2Plugin = $this->getOauth2Plugin();
            $this->accountClient = new Client();
            $this->accountClient->setDescription($description);
            $this->accountClient->addSubscriber($oauth2Plugin);
            $this->accountClient->setBaseUrl(CLI_ACCOUNTS_SITE . '/api/platform');
            $this->accountClient->setDefaultOption('verify', CLI_VERIFY_SSL_CERT);
            $this->accountClient->setUserAgent($this->getUserAgent());
            $this->accountClient->addSubscriber($this->getCacheSubscriber());
        }

        return $this->accountClient;
    }

    /**
     * Return an instance of the Guzzle client for the Platform endpoint.
     *
     * @param string $baseUrl The base url for API calls, usually the project URI.
     *
     * @return Client
     */
    protected function getPlatformClient($baseUrl)
    {
        if (!$this->platformClient) {
            $description = ServiceDescription::factory(CLI_ROOT . '/services/platform.php');
            $oauth2Plugin = $this->getOauth2Plugin();
            $this->platformClient = new PlatformClient();
            $this->platformClient->setDescription($description);
            $this->platformClient->addSubscriber($oauth2Plugin);
            $this->platformClient->setUserAgent($this->getUserAgent());
            $this->platformClient->addSubscriber($this->getCacheSubscriber());
        }

        // The base url can change between two requests in the same command,
        // so it needs to be explicitly set every time.
        $this->platformClient->setBaseUrl($baseUrl);

        return $this->platformClient;
    }

    /**
     * Get an HTTP User Agent string representing this application.
     *
     * @return string
     */
    protected function getUserAgent()
    {
        $application = $this->getApplication();
        $name = str_replace(' ', '-', $application->getName());
        $version = $application->getVersion();
        $url = 'https://github.com/platformsh/platformsh-cli';
        return "$name/$version (+$url)";
    }

    /**
     * @return CachePlugin
     */
    protected function getCacheSubscriber()
    {
        $cache = new FilesystemCache($this->getCacheDir());
        $adapter = new DoctrineCacheAdapter($cache);
        $storage = new DefaultCacheStorage($adapter);
        return new CachePlugin(array('storage' => $storage));
    }

    /**
     * Get the current project if the user is in a project directory.
     *
     * @return array|null The current project
     */
    public function getCurrentProject()
    {
        $project = null;
        $config = LocalProject::getCurrentProjectConfig();
        if ($config) {
          $project = $this->getProject($config['id']);
          // There is a chance that the project isn't available.
          if (!$project) {
              throw new \Exception("Configured project ID not found: " . $config['id']);
          }
          $project += $config;
        }
        return $project;
    }

    /**
     * Get the current environment if the user is in a project directory.
     *
     * @param array $project The current project.
     *
     * @return array|null The current environment
     */
    public function getCurrentEnvironment($project)
    {
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            return null;
        }

        // Check whether the user has a Git upstream set to a Platform
        // environment ID.
        $gitHelper = $this->getHelper('git');
        $gitHelper->setDefaultRepositoryDir($projectRoot . '/' . LocalProject::REPOSITORY_DIR);
        $upstream = $gitHelper->getUpstream();
        if ($upstream && strpos($upstream, '/') !== false) {
            list(, $potentialEnvironment) = explode('/', $upstream, 2);
            $environment = $this->getEnvironment($potentialEnvironment, $project);
            if ($environment) {
                return $environment;
            }
        }

        // There is no Git remote set, or it's set to a non-Platform URL.
        // Fall back to trying the current branch name.
        $currentBranch = $gitHelper->getCurrentBranch();
        if ($currentBranch) {
            $currentBranchSanitized = Environment::sanitizeId($currentBranch);
            $environment = $this->getEnvironment($currentBranchSanitized, $project);
            if ($environment) {
                return $environment;
            }
        }

        return null;
    }

    /**
     * Return the user's projects.
     *
     * The projects are persisted in config, refreshed in PlatformListCommand.
     * Most platform commands (such as the environment ones) operate on a
     * project, so this persistence allows them to avoid loading the platform
     * list each time.
     *
     * @param boolean $refresh Whether to refresh the static cache.
     *
     * @return array The user's projects.
     */
    public function getProjects($refresh = false)
    {
        static $projects;
        if (!$refresh && !empty($projects)) {
            return $projects;
        }
        $accountClient = $this->getAccountClient();
        $data = $accountClient->getProjects();
        // Extract the project ID and re-key the array.
        $projects = array();
        foreach ($data['projects'] as $project) {
            if (!empty($project['uri'])) {
                $urlParts = explode('/', $project['uri']);
                $id = end($urlParts);
                $project['id'] = $id;
                $projects[$id] = $project;
            }
        }
        return $projects;
    }

    /**
     * Return the user's project with the given id.
     *
     * @return array|null
     */
    protected function getProject($id)
    {
        $projects = $this->getProjects();
        if (!isset($projects[$id])) {
            // The list of projects is cached and might be older than the
            // requested project, so refetch it as a precaution.
            $projects = $this->getProjects(true);
        }

        return isset($projects[$id]) ? $projects[$id] : null;
    }

    /**
     * Return the user's environments.
     *
     * The environments are persisted in config, so that they can be compared
     * on next load. This allows the drush aliases to be refreshed only
     * if the environment list has changed.
     *
     * @param array $project The project.
     * @param bool $refresh Whether to refresh the list.
     * @param bool $updateAliases Whether to update Drush aliases if the list changes.
     *
     * @return array The user's environments.
     */
    public function getEnvironments($project, $refresh = false, $updateAliases = true)
    {
        static $staticCache = array();
        $projectId = $project['id'];
        if (!$refresh && !empty($staticCache[$projectId])) {
            return $staticCache[$projectId];
        }
        $this->loadConfig();
        if (!$refresh && !empty($this->config['environments'][$projectId])) {
            return $this->config['environments'][$projectId];
        }

        if (!isset($this->config['environments'][$projectId])) {
            $this->config['environments'][$projectId] = array();
        }

        // Fetch and assemble a list of environments.
        $urlParts = parse_url($project['endpoint']);
        $baseUrl = $urlParts['scheme'] . '://' . $urlParts['host'];
        $client = $this->getPlatformClient($project['endpoint']);
        $environments = array();
        foreach ($client->getEnvironments() as $environment) {
            // The environments endpoint is temporarily not serving
            // absolute urls, so we need to construct one.
            $environment['endpoint'] = $baseUrl . $environment['_links']['self']['href'];
            $environments[$environment['id']] = $environment;
        }

        // Recreate the aliases if the list of environments has changed.
        if ($updateAliases && $this->config['environments'][$projectId] != $environments) {
            $this->updateDrushAliases($project, $environments);
        }

        $this->config['environments'][$projectId] = $environments;

        $staticCache[$projectId] = $environments;
        return $environments;
    }

    /**
     * Get a single environment.
     *
     * @param string $id The environment ID to load.
     * @param array $project The project.
     * @param bool $refresh
     *
     * @return array|null The environment, or null if not found.
     */
    protected function getEnvironment($id, $project = null, $refresh = false)
    {
        $project = $project ?: $this->getCurrentProject();
        if (!$project) {
            return null;
        }

        // Cache not found environments.
        static $notFound = array();
        $cacheKey = $project['id'] . ':' . $id;
        if (!$refresh && isset($notFound[$cacheKey])) {
            return null;
        }

        $this->loadConfig();
        $projectId = $project['id'];
        if ($refresh || empty($this->config['environments'][$projectId][$id])) {
            $client = $this->getPlatformClient($project['endpoint']);
            $environment = HalResource::get($id, $project['endpoint'] . '/environments', $client);
            if (!$environment) {
                $notFound[$cacheKey] = true;
                return null;
            }
            $urlParts = parse_url($project['endpoint']);
            $baseUrl = $urlParts['scheme'] . '://' . $urlParts['host'];
            $environment = $environment->getData();
            $environment['endpoint'] = $baseUrl . $environment['_links']['self']['href'];
            $this->config['environments'][$projectId][$id] = $environment;

            // Recreate the aliases, assuming the list of environments has changed.
            $this->updateDrushAliases($project, $this->config['environments'][$projectId]);
        }
        return $this->config['environments'][$projectId][$id];
    }

    /**
     * @param array $project
     * @param array $environments
     */
    protected function updateDrushAliases(array $project, array $environments) {
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            return;
        }
        // Double-check that the passed project is the current one.
        $currentProject = $this->getCurrentProject();
        if (!$currentProject || $currentProject['id'] != $project['id']) {
            return;
        }
        $drushHelper = $this->getHelper('drush');
        $drushHelper->setHomeDir($this->getHelper('fs')->getHomeDirectory());
        $drushHelper->createAliases($project, $projectRoot, $environments);
    }

    /**
     * @return string|false
     */
    protected function getProjectRoot()
    {
        return LocalProject::getProjectRoot();
    }

    /**
     * Return the user's domains.
     *
     * @param array $project The project.
     *
     * @return array The user's domains.
     */
    protected function getDomains($project)
    {
        $this->loadConfig();
        $projectId = $project['id'];
        if (!isset($this->config['domains'][$projectId])) {
            $this->config['domains'][$projectId] = array();
        }

        // Fetch and assemble a list of domains.
        $client = $this->getPlatformClient($project['endpoint']);
        $domains = array();
        foreach ($client->getDomains() as $domain) {
            $domains[$domain['id']] = $domain;
        }

        $this->config['domains'][$projectId] = $domains;

        return $this->config['domains'][$projectId];
    }

    /**
     * Detect automatically whether the output is a TTY terminal.
     *
     * @param OutputInterface $output
     *
     * @return bool
     */
    protected function isTerminal(OutputInterface $output)
    {
        if (!$output instanceof StreamOutput) {
            return false;
        }
        // If the POSIX extension doesn't exist, default to true. It's better
        // for Windows users if we assume the output is a terminal.
        if (!function_exists('posix_isatty')) {
            return true;
        }
        // This uses the same test as StreamOutput::hasColorSupport().
        $stream = $output->getStream();
        return @posix_isatty($stream);
    }

    /**
     * Add the --project option.
     *
     * @return self
     */
    protected function addProjectOption()
    {
        return $this->addOption('project', null, InputOption::VALUE_OPTIONAL, 'The project ID');
    }

    /**
     * Add the --environment option.
     *
     * @return self
     */
    protected function addEnvironmentOption()
    {
        return $this->addOption('environment', null, InputOption::VALUE_OPTIONAL, 'The environment ID');
    }

    /**
     * @param string $projectId
     * @return array
     */
    protected function selectProject($projectId = null)
    {
        if (!empty($projectId)) {
            $project = $this->getProject($projectId);
            if (!$project) {
                throw new \RuntimeException('Specified project not found: ' . $projectId);
            }
        } else {
            $project = $this->getCurrentProject();
            if (!$project) {
                throw new \RuntimeException(
                  "Could not determine the current project."
                  . "\nSpecify it manually using --project or go to a project directory."
                );
            }
        }
        return $project;
    }

    /**
     * @param string $environmentId
     *
     * @return array
     */
    protected function selectEnvironment($environmentId = null)
    {
        if (!empty($environmentId)) {
            $environment = $this->getEnvironment($environmentId, $this->project);
            if (!$environment) {
                throw new \RuntimeException("Specified environment not found: " . $environmentId);
            }
        } else {
            $environment = $this->getCurrentEnvironment($this->project);
            if (!$environment) {
                throw new \RuntimeException(
                  "Could not determine the current environment."
                  . "\nSpecify it manually using --environment or go to a project directory."
                );
            }
        }
        return $environment;
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return bool
     */
    protected function validateInput(InputInterface $input, OutputInterface $output)
    {
        $projectId = $input->hasOption('project') ? $input->getOption('project') : null;
        try {
            $this->project = $this->selectProject($projectId);
            $envOptionName = 'environment';
            if ($input->hasArgument($this->envArgName) && $input->getArgument($this->envArgName)) {
                if ($input->hasOption($envOptionName) && $input->getOption($envOptionName)) {
                    throw new \InvalidArgumentException(sprintf("You cannot use both the '%s' argument and the '--%s' option", $this->envArgName, $envOptionName));
                }
                $argument = $input->getArgument($this->envArgName);
                if (is_array($argument) && count($argument) == 1) {
                    $argument = $argument[0];
                }
                if (!is_array($argument)) {
                    $this->environment = $this->selectEnvironment($argument);
                }
            }
            elseif ($input->hasOption($envOptionName)) {
                $this->environment = $this->selectEnvironment($input->getOption($envOptionName));
            }
        }
        catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return false;
        }
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $output->writeln("Selected project: " . $this->project['id']);
            $environmentId = $this->environment ? $this->environment['id'] : '[none]';
            $output->writeln("Selected environment: $environmentId");
        }
        return true;
    }

    /**
     * Delete the config file and cache directory (log the user out).
     */
    protected function deleteConfig()
    {
        $fsHelper = $this->getHelper('fs');
        $fsHelper->remove($this->getCacheDir());
        $fsHelper->remove($this->getConfigPath());
        // Prevent config from being saved in the destructor.
        $this->deleteConfig = true;
    }

    /**
     * Get the config path.
     *
     * @return string
     */
    protected function getCacheDir()
    {
        $dir = $this->getHelper('fs')
          ->getHomeDirectory()
          . '/.platformsh-cli/cache';
        if (!file_exists($dir)) {
            if (!mkdir($dir, 0700, true)) {
                throw new \RuntimeException("Cannot create directory: $dir");
            }
            chmod($dir, 0700);
        }
        return $dir;
    }

    /**
     * Get the config path.
     *
     * @return string
     */
    protected function getConfigPath()
    {
        $filename = $this->getHelper('fs')->getHomeDirectory() . '/.platform';
        if (!file_exists($filename)) {
            touch($filename);
            chmod($filename, 0600);
        }
        return $filename;
    }

    /**
     * Save the 'config' for the session (authentication tokens, etc.).
     *
     * @todo this currently caches the environments, that can be removed when the API has good HTTP caching
     */
    protected function saveConfig()
    {
        if ($this->deleteConfig || !is_array($this->config)) {
            return;
        }
        if ($this->oauth2Plugin) {
            // Save the access token for future requests.
            $this->config['access_token'] = $this->oauth2Plugin->getAccessToken();
        }

        if ($this->config == $this->originalConfig) {
            // Nothing has changed.
            return;
        }

        $dumper = new Dumper();
        file_put_contents($this->getConfigPath(), $dumper->dump($this->config));
    }

    /**
     * Destructor.
     *
     * Ensure configuration is saved after every command.
     */
    public function __destruct()
    {
        $this->saveConfig();
    }

}
