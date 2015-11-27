<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Exception\LoginRequiredException;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Helper\FilesystemHelper;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Local\Toolstack\Drupal;
use Platformsh\Cli\Util\CacheUtil;
use Platformsh\Client\Connection\Connector;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Platformsh\Client\Model\ProjectAccess;
use Platformsh\Client\PlatformClient;
use Platformsh\Client\Session\Storage\File;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

abstract class PlatformCommand extends Command
{
    use HasExamplesTrait;

    /** @var PlatformClient|null */
    private static $client;

    /** @var string */
    protected static $sessionId = 'default';

    /** @var string */
    protected static $configDir;

    /** @var string|null */
    protected static $apiToken;

    /** @var bool */
    protected static $interactive = false;

    /** @var OutputInterface|null */
    protected $output;

    /** @var OutputInterface|null */
    protected $stdErr;

    protected $envArgName = 'environment';

    protected $projectsTtl;
    protected $environmentsTtl;
    protected $usersTtl;

    private $hiddenInList = false;
    private $hiddenAliases = array();

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
        $this->usersTtl = getenv('PLATFORMSH_CLI_USERS_TTL') ?: 3600;

        if (getenv('PLATFORMSH_CLI_SESSION_ID')) {
            self::$sessionId = getenv('PLATFORMSH_CLI_SESSION_ID');
        }
        if (!isset(self::$apiToken) && getenv('PLATFORMSH_CLI_API_TOKEN')) {
            self::$apiToken = getenv('PLATFORMSH_CLI_API_TOKEN');
        }

        // Initialize the local file-based cache.
        CacheUtil::setCacheDir($this->getConfigDir() . '/cache');
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
                $session->setStorage(new File($this->getSessionsDir()));
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
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        self::$interactive = $input->isInteractive();

        // Tune error reporting based on the output verbosity.
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            error_reporting(E_ALL);
        }
        elseif ($output->getVerbosity() === OutputInterface::VERBOSITY_QUIET) {
            error_reporting(false);
        }
        else {
            error_reporting(E_PARSE);
        }

        $this->_deleteOldCaches();
    }

    /**
     * Clean up old cache directories from previous versions.
     *
     * @todo remove in version 3
     */
    private function _deleteOldCaches()
    {
        static $alreadyRunOnce;
        if ($alreadyRunOnce) {
            return;
        }
        $alreadyRunOnce = true;
        $sessionsDir = $this->getSessionsDir();
        if (!is_dir($sessionsDir)) {
            return;
        }
        foreach (scandir($sessionsDir) as $filename) {
            if (strpos($filename, 'sess-') !== 0) {
                continue;
            }
            $sessionDir = $sessionsDir . DIRECTORY_SEPARATOR . $filename;
            foreach (scandir($sessionDir) as $filename2) {
                if ($filename2[0] === '.' || strpos($filename2, '.json') !== false) {
                    continue;
                }
                if (!isset($fs)) {
                    $fs = new FilesystemHelper();
                }
                $fs->remove($sessionDir . DIRECTORY_SEPARATOR . $filename2);
            }
        }
    }

    /**
     * Clear the cache.
     */
    protected function clearCache()
    {
        CacheUtil::getCache()->flushAll();
    }

    /**
     * @return string
     */
    protected function getConfigDir()
    {
        if (!isset(self::$configDir)) {
            $fs = new FilesystemHelper();
            self::$configDir = $fs->getHomeDirectory() . '/.platformsh';
        }

        return self::$configDir;
    }

    /**
     * @return string
     */
    protected function getSessionsDir()
    {
        return $this->getConfigDir() . '/.session';
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
        $exitCode = $this->runOtherCommand('login');
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
     * @param string $totp     The user's TFA one-time password.
     */
    protected function authenticateUser($email, $password, $totp = null)
    {
        $this->getClient(false)
             ->getConnector()
             ->logIn($email, $password, true, $totp);
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
     * @param Project $expectedProject The expected project.
     *
     * @return Environment|false The current environment.
     */
    public function getCurrentEnvironment(Project $expectedProject = null)
    {
        if (!($projectRoot = $this->getProjectRoot())
          || !($project = $this->getCurrentProject())
          || ($expectedProject !== null && $expectedProject->id !== $project->id)) {
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
        $cacheKey = sprintf('%:projects', self::$sessionId);

        /** @var Project[] $projects */
        $projects = array();

        $cache = CacheUtil::getCache();

        if ($refresh || !$cache->contains($cacheKey)) {
            foreach ($this->getClient()->getProjects() as $project) {
                $projects[$project->id] = $project;
            }

            $cachedProjects = array();
            foreach ($projects as $id => $project) {
                $cachedProjects[$id] = $project->getData();
                $cachedProjects[$id]['_endpoint'] = $project->getUri(true);
                $cachedProjects[$id]['git'] = $project->getGitUrl();
            }

            $cache->save($cacheKey, $cachedProjects, $this->projectsTtl);
        } else {
            $connector = $this->getClient(false)
                              ->getConnector();
            $client = $connector->getClient();
            foreach ((array) $cache->fetch($cacheKey) as $id => $data) {
                $projects[$id] = new Project($data, $data['_endpoint'], $client);
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
        // Allow the specified project to be a full URL.
        if (strpos($id, '//') !== false) {
            $url = $id;
            $id = basename($url);
            $host = parse_url($url, PHP_URL_HOST);
        }

        // Find the project in the user's main project list. This uses a cache.
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
        $cache = CacheUtil::getCache();
        $cached = $cache->contains($cacheKey);

        if ($refresh || !$cached) {
            $environments = array();
            $toCache = array();
            foreach ($project->getEnvironments() as $environment) {
                $environments[$environment->id] = $environment;
                $toCache[$environment->id] = $environment->getData();
            }

            // Recreate the aliases if the list of environments has changed.
            if ($updateAliases && (!$cached || array_diff_key($environments, $cache->fetch($cacheKey)))) {
                $this->updateDrushAliases($project, $environments);
            }

            $cache->save($cacheKey, $toCache, $this->environmentsTtl);
        } else {
            $environments = array();
            $connector = $this->getClient(false)
                              ->getConnector();
            $endpoint = $project->hasLink('self') ? $project->getLink('self', true) : $project->getProperty('endpoint');
            $client = $connector->getClient();
            foreach ((array) $cache->fetch($cacheKey) as $id => $data) {
                $environments[$id] = new Environment($data, $endpoint, $client);
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
        $cacheKey = $project->id . ':' . $id;
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
     * Get a user's account info.
     *
     * @param ProjectAccess $user
     * @param bool $reset
     *
     * @return array
     *   An array containing 'email' and 'display_name'.
     */
    protected function getAccount(ProjectAccess $user, $reset = false)
    {
        $cacheKey = 'account:' . $user->id;
        $cache = CacheUtil::getCache();
        if ($reset || !($details = $cache->fetch($cacheKey))) {
            $details = $user->getAccount()->getProperties();
            $cache->save($cacheKey, $details, $this->usersTtl);
        }

        return $details;
    }

    /**
     * Clear the environments cache for a project.
     *
     * Use this after creating/deleting/updating environment(s).
     *
     * @param Project $project
     */
    public function clearEnvironmentsCache(Project $project = null)
    {
        $project = $project ?: $this->getSelectedProject();
        CacheUtil::getCache()->delete('environments:' . $project->id);
    }

    /**
     * Clear the projects cache.
     */
    protected function clearProjectsCache()
    {
        CacheUtil::getCache()->delete(sprintf('%s:projects', self::$sessionId));
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
        if (!$currentProject || $currentProject->id != $project->id) {
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
    public function getProjectRoot()
    {
        return $this->projectRoot ?: LocalProject::getProjectRoot();
    }

    /**
     * @return bool
     */
    protected function selectedProjectIsCurrent()
    {
        $current = $this->getCurrentProject();
        if (!$current || !$this->hasSelectedProject()) {
            return false;
        }

        return $current->id === $this->getSelectedProject()->id;
    }

    /**
     * Warn the user that the remote environment needs rebuilding.
     */
    protected function rebuildWarning()
    {
        $this->stdErr->writeln('<comment>The remote environment must be rebuilt for the change to take effect.</comment>');
        $this->stdErr->writeln("Use 'git push' with new commit(s) to trigger a rebuild.");
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
        $this->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'The project ID');
        $this->addOption('host', null, InputOption::VALUE_REQUIRED, "The project's API hostname");

        return $this;
    }

    /**
     * Add the --environment option.
     *
     * @return self
     */
    protected function addEnvironmentOption()
    {
        return $this->addOption('environment', 'e', InputOption::VALUE_REQUIRED, 'The environment ID');
    }

    /**
     * Add the --app option.
     *
     * @return self
     */
    protected function addAppOption()
    {
        return $this->addOption('app', null, InputOption::VALUE_REQUIRED, 'The remote application name');
    }

    /**
     * Add the --no-wait option.
     *
     * @param string $description
     *
     * @return self
     */
    protected function addNoWaitOption($description = 'Do not wait for the operation to complete')
    {
        return $this->addOption('no-wait', null, InputOption::VALUE_NONE, $description);
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
     * Find the name of the app the user wants to use for an SSH command.
     *
     * @param InputInterface $input
     *   The user input object.
     * @param callable|null $filter
     *   A filter callback that takes one argument: a LocalApplication object.
     *
     * @return string|null
     *   The application name, or null if it could not be found.
     */
    protected function selectApp(InputInterface $input, callable $filter = null)
    {
        $appName = $input->getOption('app');
        if ($appName) {
            return $appName;
        }
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot || !$this->selectedProjectIsCurrent() || !is_dir($projectRoot . '/' . LocalProject::REPOSITORY_DIR)) {
            return null;
        }
        /** @var LocalApplication[] $apps */
        $apps = LocalApplication::getApplications($projectRoot . '/' . LocalProject::REPOSITORY_DIR);

        if ($filter) {
            $apps = array_filter($apps, $filter);
        }

        if (count($apps) > 1 && $input->isInteractive()) {
            /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $questionHelper */
            $questionHelper = $this->getHelper('question');
            $choices = [];
            foreach ($apps as $app) {
                $choices[$app->getName()] = $app->getName();
            }
            $appName = $questionHelper->choose($choices, 'Enter a number to choose an app:', $input, $this->stdErr);
        }
        elseif (count($apps) === 1) {
            $app = reset($apps);
            $appName = $app->getName();
            if ($this->stdErr->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $this->stdErr->writeln("Selected app: " . $appName);
            }
        }

        return $appName;
    }

    /**
     * @param InputInterface  $input
     * @param bool $envNotRequired
     */
    protected function validateInput(InputInterface $input, $envNotRequired = null)
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

        if ($this->stdErr->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $this->stdErr->writeln("Selected project: " . $this->project->id);
            $environmentId = $this->environment ? $this->environment->id : '[none]';
            $this->stdErr->writeln("Selected environment: $environmentId");
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

    /**
     * Run another CLI command.
     *
     * @param string         $name
     *   The name of the other command.
     * @param array          $arguments
     *   Arguments for the other command.
     * @param InputInterface $input
     *   The input to the current command.
     *
     * @return int
     */
    protected function runOtherCommand($name, array $arguments = array(), InputInterface $input = null)
    {
        /** @var PlatformCommand $command */
        $command = $this->getApplication()->find($name);
        // Pass on the project root to the other command.
        if ($root = $this->getProjectRoot()) {
            $command->setProjectRoot($root);
        }

        // Pass on interactivity arguments to the other command.
        if ($input) {
            $arguments += array(
              '--yes' => $input->getOption('yes'),
              '--no' => $input->getOption('no'),
            );
        }

        $cmdInput = new ArrayInput(array('command' => $name) + $arguments);

        return $command->run($cmdInput, $this->output);
    }

    /**
     * Add aliases that should be hidden from help.
     *
     * @see parent::setAliases()
     *
     * @param array $hiddenAliases
     *
     * @return self
     */
    protected function setHiddenAliases(array $hiddenAliases)
    {
        $this->hiddenAliases = $hiddenAliases;
        $this->setAliases(array_merge($this->getAliases(), $hiddenAliases));

        return $this;
    }

    /**
     * Get aliases that should be visible in help.
     *
     * @return array
     */
    public function getVisibleAliases()
    {
        return array_diff($this->getAliases(), $this->hiddenAliases);
    }
}
