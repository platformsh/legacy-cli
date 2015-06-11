<?php

namespace Platformsh\Cli\Command;

use Doctrine\Common\Cache\FilesystemCache;
use Doctrine\Common\Cache\VoidCache;
use Platformsh\Cli\Exception\LoginRequiredException;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Helper\FilesystemHelper;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Local\Toolstack\Drupal;
use Platformsh\Client\Connection\Connector;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Platformsh\Client\PlatformClient;
use Platformsh\Client\Session\Storage\File;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

abstract class PlatformCommand extends Command
{

    /** @var PlatformClient|null */
    private static $client;

    /** @var \Doctrine\Common\Cache\CacheProvider|null */
    private static $cache;

    /** @var string */
    protected static $sessionId = 'default';

    /** @var string|null */
    protected static $apiToken;

    /** @var bool */
    protected static $interactive = false;

    /** @var OutputInterface|null */
    protected $output;

    protected $envArgName = 'environment';

    protected $projectsTtl;
    protected $environmentsTtl;

    private $hiddenInList = false;

    /**
     * The project, selected either by an option or the CWD.
     *
     * @var Project|false
     */
    private $project;

    /**
     * The environment, selected by an option, an argument, or the CWD.
     *
     * @var Environment|false
     */
    private $environment;

    /**
     * @see self::getProjectRoot()
     * @see self::setProjectRoot()
     *
     * @var string|false
     */
    private $projectRoot = false;

    /**
     * The local project configuration.
     *
     * @var array
     */
    private $projectConfig = [];

    public function __construct($name = null)
    {
        parent::__construct($name);

        $this->projectsTtl = getenv('PLATFORMSH_CLI_PROJECTS_TTL') ?: 3600;
        $this->environmentsTtl = getenv('PLATFORMSH_CLI_ENVIRONMENTS_TTL') ?: 600;

        if (getenv('PLATFORMSH_CLI_SESSION_ID')) {
            self::$sessionId = getenv('PLATFORMSH_CLI_SESSION_ID');
        }
        if (!isset(self::$apiToken) && getenv('PLATFORMSH_CLI_API_TOKEN')) {
            self::$apiToken = getenv('PLATFORMSH_CLI_API_TOKEN');
        }
        if (!isset(self::$cache)) {
            // Note: the cache directory is based on self::$sessionId.
            self::$cache = getenv('PLATFORMSH_CLI_DISABLE_CACHE') ? new VoidCache() : new FilesystemCache($this->getCacheDir());
        }
    }

    /**
     * Make the command hidden in the list.
     *
     * @return $this
     */
    public function setHiddenInList()
    {
        $this->hiddenInList = true;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function isEnabled() {
        $enabled = parent::isEnabled();

        // Hide the command in the list, if necessary.
        if ($enabled && $this->hiddenInList) {
            global $argv;
            $enabled = !isset($argv[1]) || $argv[1] != 'list';
        }

        return $enabled;
    }

    /**
     * Get the API client object.
     *
     * @param bool $autoLogin Whether to log in, if the client is not already
     *                        authenticated (default: true).
     *
     * @return PlatformClient
     */
    public function getClient($autoLogin = true)
    {
        if (!isset(self::$client)) {
            $connectorOptions = [];
            if (getenv('PLATFORMSH_CLI_ACCOUNTS_SITE')) {
                $connectorOptions['accounts'] = getenv('PLATFORMSH_CLI_ACCOUNTS_SITE');
            }
            $connectorOptions['verify'] = !getenv('PLATFORMSH_CLI_SKIP_SSL');
            $connectorOptions['debug'] = (bool) getenv('PLATFORMSH_CLI_DEBUG');
            $connectorOptions['client_id'] = 'platform-cli';
            $connectorOptions['user_agent'] = $this->getUserAgent();

            // Proxy support with the http_proxy or https_proxy environment
            // variables.
            $proxies = array();
            foreach (array('https', 'http') as $scheme) {
                $proxies[$scheme] = str_replace('http://', 'tcp://', getenv($scheme . '_proxy'));
            }
            $proxies = array_filter($proxies);
            if (count($proxies)) {
                $connectorOptions['proxy'] = count($proxies) == 1 ? reset($proxies) : $proxies;
            }

            $connector = new Connector($connectorOptions);

            // If an API token is set, that's all we need to authenticate.
            if (isset(self::$apiToken)) {
                $connector->setApiToken(self::$apiToken);
            }
            // Otherwise, set up a persistent session to store OAuth2 tokens. By
            // default, this will be stored in a JSON file:
            // $HOME/.platformsh/.session/sess-cli-default/sess-cli-default.json
            else {
                $session = $connector->getSession();
                $session->setId('cli-' . self::$sessionId);
                $session->setStorage(new File());
            }

            self::$client = new PlatformClient($connector);

            if ($autoLogin && !$connector->isLoggedIn()) {
                $this->login();
            }
        }

        return self::$client;
    }

    /**
     * @inheritdoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        self::$interactive = $input->isInteractive();
    }

    /**
     * Clear the cache.
     */
    protected function clearCache()
    {
        self::$cache->flushAll();
    }

    /**
     * @return string
     */
    protected function getCacheDir()
    {
        $sessionId = 'cli-' . preg_replace('/[\W]+/', '-', self::$sessionId);

        $fs = new FilesystemHelper();
        return $fs->getHomeDirectory() . '/.platformsh/.session/sess-' . $sessionId;
    }

    /**
     * Get an HTTP User Agent string representing this application.
     *
     * @return string
     */
    protected function getUserAgent()
    {
        $application = $this->getApplication();
        $version = $application ? $application->getVersion() : 'dev';
        $name = 'Platform.sh-CLI';
        $url = 'https://github.com/platformsh/platformsh-cli';

        return "$name/$version (+$url)";
    }

    /**
     * Log in the user.
     */
    protected function login()
    {
        if (!$this->output || !self::$interactive) {
            throw new LoginRequiredException();
        }
        $application = $this->getApplication();
        $command = $application->find('login');
        $input = new ArrayInput(array(
          'command' => 'login',
        ));
        $exitCode = $command->run($input, $this->output);
        if ($exitCode) {
            throw new \Exception('Login failed');
        }
    }

    /**
     * Check if the user is logged in.
     *
     * @return bool
     */
    protected function isLoggedIn()
    {
        return $this->getClient(false)
                    ->getConnector()
                    ->isLoggedIn();
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
     * Authenticate the user using the given credentials.
     *
     * The credentials are used to acquire a set of tokens (access token
     * and refresh token) that are then stored and used for all future requests.
     * The actual credentials are never stored, there is no need to reuse them
     * since the refresh token never expires.
     *
     * @param string $email    The user's email.
     * @param string $password The user's password.
     */
    protected function authenticateUser($email, $password)
    {
        $this->getClient(false)
             ->getConnector()
             ->logIn($email, $password, true);
    }

    /**
     * Get the current project if the user is in a project directory.
     *
     * @throws \RuntimeException
     *
     * @return Project|false The current project
     */
    public function getCurrentProject()
    {
        if (!$projectRoot = $this->getProjectRoot()) {
            return false;
        }

        $project = false;
        $config = $this->getProjectConfig($projectRoot);
        if ($config) {
            $project = $this->getProject($config['id'], isset($config['host']) ? $config['host'] : null);
            // There is a chance that the project isn't available.
            if (!$project) {
                $filename = LocalProject::getProjectRoot() . '/' . LocalProject::PROJECT_CONFIG;
                throw new \RuntimeException(
                  "Project ID not found: " . $config['id']
                  . "\nEither you do not have access to the project on Platform.sh, or it no longer exists."
                  . "\nThe project ID was determined from the file: " . $filename
                );
            }
        }

        return $project;
    }

    /**
     * Get the project configuration.
     *
     * @param string $projectRoot
     *
     * @return array
     */
    protected function getProjectConfig($projectRoot)
    {
        if (!isset($this->projectConfig[$projectRoot])) {
            $this->projectConfig[$projectRoot] = LocalProject::getProjectConfig($projectRoot) ?: [];
        }

        return $this->projectConfig[$projectRoot];
    }

    /**
     * Set a value in the project configuration.
     *
     * @param string $key
     * @param mixed $value
     * @param string $projectRoot
     */
    protected function setProjectConfig($key, $value, $projectRoot)
    {
        unset($this->projectConfig[$projectRoot]);
        LocalProject::writeCurrentProjectConfig($key, $value, $projectRoot);
    }

    /**
     * Get the current environment if the user is in a project directory.
     *
     * @param Project $project The current project.
     *
     * @return Environment|false The current environment
     */
    public function getCurrentEnvironment(Project $project)
    {
        if (!$projectRoot = $this->getProjectRoot()) {
            return false;
        }

        $gitHelper = $this->getHelper('git');
        $gitHelper->setDefaultRepositoryDir($this->getProjectRoot() . '/' . LocalProject::REPOSITORY_DIR);
        $currentBranch = $gitHelper->getCurrentBranch();

        // Check if there is a manual mapping set for the current branch.
        if ($currentBranch) {
            $config = $this->getProjectConfig($projectRoot);
            if (!empty($config['mapping'][$currentBranch])) {
                $environment = $this->getEnvironment($config['mapping'][$currentBranch], $project);
                if ($environment) {
                    return $environment;
                }
                else {
                    unset($config['mapping'][$currentBranch]);
                    $this->setProjectConfig('mapping', $config['mapping'], $projectRoot);
                }
            }
        }

        // Check whether the user has a Git upstream set to a Platform
        // environment ID.
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
        if ($currentBranch) {
            $currentBranchSanitized = Environment::sanitizeId($currentBranch);
            $environment = $this->getEnvironment($currentBranchSanitized, $project);
            if ($environment) {
                return $environment;
            }
        }

        return false;
    }

    /**
     * Return the user's projects.
     *
     * @param boolean $refresh Whether to refresh the list of projects.
     *
     * @return Project[] The user's projects, keyed by project ID.
     */
    public function getProjects($refresh = false)
    {
        $cacheKey = 'projects';

        /** @var Project[] $projects */
        $projects = array();

        if ($refresh || !self::$cache->contains($cacheKey)) {
            foreach ($this->getClient()->getProjects() as $project) {
                $projects[$project->id] = $project;
            }

            $cachedProjects = array();
            foreach ($projects as $id => $project) {
                $cachedProjects[$id] = $project->getData();
                $cachedProjects[$id]['_endpoint'] = $project->getUri(true);
                $cachedProjects[$id]['git'] = $project->getGitUrl();
            }

            self::$cache->save($cacheKey, $cachedProjects, $this->projectsTtl);
        } else {
            $connector = $this->getClient(false)
                              ->getConnector();
            $client = $connector->getClient();
            foreach ((array) self::$cache->fetch($cacheKey) as $id => $data) {
                $projects[$id] = Project::wrap($data, $data['_endpoint'], $client);
            }
        }

        return $projects;
    }

    /**
     * Return the user's project with the given id.
     *
     * @param string $id
     * @param string $host
     * @param bool   $refresh
     *
     * @return Project|false
     */
    protected function getProject($id, $host = null, $refresh = false)
    {
        $projects = $this->getProjects($refresh);
        if (isset($projects[$id])) {
            return $projects[$id];
        }

        // Get the project directly if a hostname is specified.
        if (!empty($host)) {
            $scheme = 'https';
            if (($pos = strpos($host, '//')) !== false) {
                $scheme = parse_url($host, PHP_URL_SCHEME);
                $host = substr($host, $pos + 2);
            }
            return $this->getClient()->getProjectDirect($id, $host, $scheme != 'http');
        }

        return false;
    }

    /**
     * Return the user's environments.
     *
     * @param Project $project       The project.
     * @param bool    $refresh       Whether to refresh the list.
     * @param bool    $updateAliases Whether to update Drush aliases if the list changes.
     *
     * @return Environment[] The user's environments.
     */
    public function getEnvironments(Project $project = null, $refresh = false, $updateAliases = true)
    {
        $project = $project ?: $this->getSelectedProject();
        $projectId = $project->getProperty('id');

        $cacheKey = 'environments:' . $projectId;
        $cached = self::$cache->contains($cacheKey);

        if ($refresh || !$cached) {
            $environments = array();
            $toCache = array();
            foreach ($project->getEnvironments() as $environment) {
                $environments[$environment['id']] = $environment;
                $toCache[$environment['id']] = $environment->getData();
            }

            // Recreate the aliases if the list of environments has changed.
            if ($updateAliases && (!$cached || array_diff_key($environments, self::$cache->fetch($cacheKey)))) {
                $this->updateDrushAliases($project, $environments);
            }

            self::$cache->save($cacheKey, $toCache, $this->environmentsTtl);
        } else {
            $environments = array();
            $connector = $this->getClient(false)
                              ->getConnector();
            $endpoint = $project->hasLink('self') ? $project->getLink('self', true) : $project['endpoint'];
            $client = $connector->getClient();
            foreach ((array) self::$cache->fetch($cacheKey) as $id => $data) {
                $environments[$id] = Environment::wrap($data, $endpoint, $client);
            }
        }

        return $environments;
    }

    /**
     * Get a single environment.
     *
     * @param string  $id      The environment ID to load.
     * @param Project $project The project.
     * @param bool    $refresh
     *
     * @return Environment|false The environment, or false if not found.
     */
    protected function getEnvironment($id, Project $project = null, $refresh = false)
    {
        $project = $project ?: $this->getCurrentProject();
        if (!$project) {
            return false;
        }

        // Statically cache not found environments.
        static $notFound = array();
        $cacheKey = $project['id'] . ':' . $id;
        if (!$refresh && isset($notFound[$cacheKey])) {
            return false;
        }

        $environments = $this->getEnvironments($project, $refresh);
        if (!isset($environments[$id])) {
            $notFound[$cacheKey] = true;

            return false;
        }

        return $environments[$id];
    }

    /**
     * @param Project       $project
     * @param Environment[] $environments
     */
    protected function updateDrushAliases(Project $project, array $environments)
    {
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            return;
        }
        // Double-check that the passed project is the current one.
        $currentProject = $this->getCurrentProject();
        if (!$currentProject || $currentProject['id'] != $project['id']) {
            return;
        }
        // Ignore the project if it doesn't contain a Drupal application.
        if (!Drupal::isDrupal($projectRoot . '/' . LocalProject::REPOSITORY_DIR)) {
            return;
        }
        /** @var \Platformsh\Cli\Helper\DrushHelper $drushHelper */
        $drushHelper = $this->getHelper('drush');
        $drushHelper->setHomeDir(
          $this->getHelper('fs')
               ->getHomeDirectory()
        );
        $drushHelper->createAliases($project, $projectRoot, $environments);
    }

    /**
     * @param string $root
     */
    protected function setProjectRoot($root)
    {
        if (!is_dir($root)) {
            throw new \InvalidArgumentException("Invalid project root: $root");
        }
        $this->projectRoot = $root;
    }

    /**
     * @return string|false
     */
    protected function getProjectRoot()
    {
        return $this->projectRoot ?: LocalProject::getProjectRoot();
    }

    /**
     * Warn the user that the remote environment needs rebuilding.
     *
     * @param OutputInterface $output
     */
    protected function rebuildWarning(OutputInterface $output)
    {
        $output->writeln('<comment>The remote environment must be rebuilt for the change to take effect.</comment>');
        $output->writeln("Use 'git push' with new commit(s) to trigger a rebuild.");
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

        /** @noinspection PhpParamsInspection */

        return @posix_isatty($stream);
    }

    /**
     * Add the --project and --host options.
     *
     * @return self
     */
    protected function addProjectOption()
    {
        $this->addOption('project', 'p', InputOption::VALUE_OPTIONAL, 'The project ID');
        $this->addOption('host', null, InputOption::VALUE_OPTIONAL, "The project's API hostname");

        return $this;
    }

    /**
     * Add the --environment option.
     *
     * @return self
     */
    protected function addEnvironmentOption()
    {
        return $this->addOption('environment', 'e', InputOption::VALUE_OPTIONAL, 'The environment ID');
    }

    /**
     * Add the --app option.
     *
     * @return self
     */
    protected function addAppOption()
    {
        return $this->addOption('app', null, InputOption::VALUE_OPTIONAL, 'The remote application name');
    }

    /**
     * @param string $projectId
     * @param string $host
     *
     * @return Project
     */
    protected function selectProject($projectId = null, $host = null)
    {
        if (!empty($projectId)) {
            $project = $this->getProject($projectId, $host);
            if (!$project) {
                throw new \RuntimeException('Specified project not found: ' . $projectId);
            }
        } else {
            $project = $this->getCurrentProject();
            if (!$project) {
                throw new RootNotFoundException(
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
                $message = "Could not determine the current environment.";
                if ($this->getProjectRoot()) {
                    throw new \RuntimeException(
                      $message . "\nSpecify it manually using --environment."
                    );
                }
                else {
                    throw new RootNotFoundException(
                      $message . "\nSpecify it manually using --environment or go to a project directory."
                    );
                }
            }
        }

        return $environment;
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @param bool $envNotRequired
     */
    protected function validateInput(InputInterface $input, OutputInterface $output, $envNotRequired = null)
    {
        // Select the project.
        $projectId = $input->hasOption('project') ? $input->getOption('project') : null;
        $projectHost = $input->hasOption('host') ? $input->getOption('host') : null;
        $this->project = $this->selectProject($projectId, $projectHost);

        // Select the environment.
        $envOptionName = 'environment';
        if ($input->hasArgument($this->envArgName) && $input->getArgument($this->envArgName)) {
            if ($input->hasOption($envOptionName) && $input->getOption($envOptionName)) {
                throw new \InvalidArgumentException(
                  sprintf(
                    "You cannot use both the '%s' argument and the '--%s' option",
                    $this->envArgName,
                    $envOptionName
                  )
                );
            }
            $argument = $input->getArgument($this->envArgName);
            if (is_array($argument) && count($argument) == 1) {
                $argument = $argument[0];
            }
            if (!is_array($argument)) {
                $this->environment = $this->selectEnvironment($argument);
            }
        } elseif ($input->hasOption($envOptionName)) {
            if ($envNotRequired && !$input->getOption($envOptionName)) {
                $this->environment = $this->getCurrentEnvironment($this->project);
            }
            else {
                $this->environment = $this->selectEnvironment($input->getOption($envOptionName));
            }
        }

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $output->writeln("Selected project: " . $this->project['id']);
            $environmentId = $this->environment ? $this->environment['id'] : '[none]';
            $output->writeln("Selected environment: $environmentId");
        }
    }

    /**
     * Check whether a project is selected.
     *
     * @return bool
     */
    protected function hasSelectedProject()
    {
        return !empty($this->project);
    }

    /**
     * Get the project selected by the user.
     *
     * The project is selected via validateInput(), if there is a --project
     * option in the command.
     *
     * @throws \BadMethodCallException
     *
     * @return Project
     */
    protected function getSelectedProject()
    {
        if (!$this->project) {
            throw new \BadMethodCallException('No project selected');
        }

        return $this->project;
    }

    /**
     * Check whether a single environment is selected.
     *
     * @return bool
     */
    protected function hasSelectedEnvironment()
    {
        return !empty($this->environment);
    }

    /**
     * Get the environment selected by the user.
     *
     * The project is selected via validateInput(), if there is an
     * --environment option in the command.
     *
     * @return Environment
     */
    protected function getSelectedEnvironment()
    {
        if (!$this->environment) {
            throw new \BadMethodCallException('No environment selected');
        }

        return $this->environment;
    }
}
