<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Guzzle\Plugin\Oauth2\Oauth2Plugin;
use CommerceGuys\Guzzle\Plugin\Oauth2\GrantType\PasswordCredentials;
use CommerceGuys\Guzzle\Plugin\Oauth2\GrantType\RefreshToken;
use CommerceGuys\Platform\Cli\Api\PlatformClient;
use CommerceGuys\Platform\Cli\Model\HalResource;
use Guzzle\Service\Client;
use Guzzle\Service\Description\ServiceDescription;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Dumper;

abstract class PlatformCommand extends Command
{
    protected $config;
    protected $oauth2Plugin;
    protected $accountClient;
    protected $platformClient;

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
     * @param bool $login
     *
     * @return array|false The configuration array or false on failure.
     */
    public function loadConfig($login = true)
    {
        if (!$this->config) {
            $configPath = $this->getHelper('fs')->getHomeDirectory() . '/.platform';
            if (!file_exists($configPath) && $login) {
                $this->login();
            }
            if (!file_exists($configPath)) {
                return false;
            }
            $yaml = new Parser();
            $this->config = $yaml->parse(file_get_contents($configPath));
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
        }
        // The base url can change between two requests in the same command,
        // so it needs to be explicitly set every time.
        $this->platformClient->setBaseUrl($baseUrl);

        return $this->platformClient;
    }

    /**
     * Get the current project if the user is in a project directory.
     *
     * @return array|null The current project
     */
    public function getCurrentProject()
    {
        $project = null;
        $config = $this->getCurrentProjectConfig();
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
     * Get the configuration for the current project.
     *
     * @return array|null
     *   The current project's configuration.
     */
    protected function getCurrentProjectConfig() {
        $projectConfig = null;
        $projectRoot = $this->getProjectRoot();
        if ($projectRoot) {
            $yaml = new Parser();
            $projectConfig = $yaml->parse(file_get_contents($projectRoot . '/.platform-project'));
        }
        return $projectConfig;
    }

    /**
     * Add a configuration value to a project.
     *
     * @param string $key The configuration key
     * @param mixed $value The configuration value
     *
     * @throws \Exception On failure
     *
     * @return array
     *   The updated project configuration.
     */
    protected function writeCurrentProjectConfig($key, $value) {
        $projectConfig = $this->getCurrentProjectConfig();
        if (!$projectConfig) {
            throw new \Exception('Current project configuration not found');
        }
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            throw new \Exception('Project root not found');
        }
        $file = $projectRoot . '/.platform-project';
        if (!is_writable($file)) {
            throw new \Exception('Project config file not writable');
        }
        $dumper = new Dumper();
        $projectConfig[$key] = $value;
        file_put_contents($file, $dumper->dump($projectConfig));
        return $projectConfig;
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
        $gitHelper->setDefaultRepositoryDir($projectRoot . '/repository');
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
            $currentBranchSanitized = $this->sanitizeEnvironmentId($currentBranch);
            $environment = $this->getEnvironment($currentBranchSanitized, $project);
            if ($environment) {
                return $environment;
            }
        }

        return null;
    }

    /**
     * Find the root of the current project.
     *
     * The project root contains a .platform-project yaml file.
     * The current directory tree is traversed until the file is found.
     *
     * @return string|null
     */
    protected function getProjectRoot()
    {
        static $projectRoot;
        if ($projectRoot !== null) {
            return $projectRoot;
        }

        $currentDir = getcwd();
        $projectRoot = null;
        while (!$projectRoot) {
            if (file_exists($currentDir . '/.platform-project')) {
                $projectRoot = $currentDir;
                break;
            }

            // The file was not found, go one directory up.
            $dirParts = explode('/', $currentDir);
            array_pop($dirParts);
            if (count($dirParts) == 0) {
                // We've reached the end, stop.
                break;
            }
            $currentDir = implode('/', $dirParts);
        }

        return $projectRoot;
    }

    /**
     * Return the user's projects.
     *
     * The projects are persisted in config, refreshed in PlatformListCommand.
     * Most platform commands (such as the environment ones) operate on a
     * project, so this persistence allows them to avoid loading the platform
     * list each time.
     *
     * @param boolean $refresh Whether to refetch the list of projects.
     *
     * @return array The user's projects.
     */
    public function getProjects($refresh = false)
    {
        $this->loadConfig();
        if (empty($this->config['projects']) || $refresh) {
            $accountClient = $this->getAccountClient();
            $data = $accountClient->getProjects();
            // Extract the project id and rekey the array.
            $projects = array();
            foreach ($data['projects'] as $project) {
                if (!empty($project['uri'])) {
                    $urlParts = explode('/', $project['uri']);
                    $id = end($urlParts);
                    $project['id'] = $id;
                    $projects[$id] = $project;
                }
            }
            $this->config['projects'] = $projects;
        }

        return $this->config['projects'];
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
        $projectId = $project['id'];
        $this->loadConfig();
        if (empty($this->config['environments'][$projectId]) || $refresh) {
            $this->config['environments'][$projectId] = array();

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
                if ($projectRoot = $this->getProjectRoot()) {
                    $drushHelper = $this->getHelper('drush');
                    $drushHelper->setHomeDir($this->getHelper('fs')->getHomeDirectory());
                    $drushHelper->createAliases($project, $projectRoot, $environments);
                }
            }

            $this->config['environments'][$projectId] = $environments;
        }

        return $this->config['environments'][$projectId];
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
        $this->loadConfig();
        $projectId = $project['id'];
        if ($refresh || empty($this->config['environments'][$projectId][$id])) {
            $client = $this->getPlatformClient($project['endpoint']);
            $environment = HalResource::get($id, $project['endpoint'] . '/environments', $client);
            if (!$environment) {
                return null;
            }
            $urlParts = parse_url($project['endpoint']);
            $baseUrl = $urlParts['scheme'] . '://' . $urlParts['host'];
            $environment = $environment->getData();
            $environment['endpoint'] = $baseUrl . $environment['_links']['self']['href'];
            $this->config['environments'][$projectId][$id] = $environment;
        }
        return $this->config['environments'][$projectId][$id];
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
     * Sanitize a proposed environment ID.
     *
     * @param string $proposed
     *
     * @return string
     */
    protected function sanitizeEnvironmentId($proposed) {
        return substr(preg_replace('/[^a-z0-9-]+/i', '', strtolower($proposed)), 0, 32);
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
            if ($input->hasOption('environment')) {
                $this->environment = $this->selectEnvironment($input->getOption('environment'));
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
     * Destructor: Write the configuration to disk.
     */
    public function __destruct()
    {
        static $written;
        if (is_array($this->config) && !$written) {
            if ($this->oauth2Plugin) {
                // Save the access token for future requests.
                $this->config['access_token'] = $this->oauth2Plugin->getAccessToken();
            }

            $configPath = $this->getHelper('fs')->getHomeDirectory() . '/.platform';
            $dumper = new Dumper();
            file_put_contents($configPath, $dumper->dump($this->config));
            $written = true;
        }
    }
}
