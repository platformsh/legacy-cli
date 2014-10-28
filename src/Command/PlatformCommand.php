<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Guzzle\Plugin\Oauth2\Oauth2Plugin;
use CommerceGuys\Guzzle\Plugin\Oauth2\GrantType\PasswordCredentials;
use CommerceGuys\Guzzle\Plugin\Oauth2\GrantType\RefreshToken;
use CommerceGuys\Platform\Cli\Local\Toolstack\Drupal;
use Guzzle\Service\Client;
use Guzzle\Service\Description\ServiceDescription;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Dumper;

class PlatformCommand extends Command
{
    protected $config;
    protected $oauth2Plugin;
    protected $accountClient;
    protected $platformClient;

    protected $project;
    protected $environment;

    /**
     * Load configuration from the user's .platform file.
     *
     * Configuration is loaded only if $this->config hasn't been populated
     * already. This allows LoginCommand to avoid writing the config file
     * before using the client for the first time.
     *
     * @return array The populated configuration array.
     */
    protected function loadConfig()
    {
        if (!$this->config) {
            $configPath = $this->getHomeDirectory() . '/.platform';
            if (!file_exists($configPath)) {
                $this->login();
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
     * Override the base setDescription method to color local commands
     * differently from remote commands.
     */
    public function setDescription($text)
    {
      $tag = $this->isLocal() ? "cyan" : "red";
      parent::setDescription("<fg={$tag}>{$text}</fg={$tag}>");

      return $this;
    }

    /**
     * Is this command used to work with your local environment or send
     * commands to the Platform remote environment? Defaults to FALSE.
     */
    public function isLocal()
    {
      return FALSE;
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
            $this->platformClient = new Client();
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
    protected function getCurrentProject()
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
    protected function getCurrentEnvironment($project)
    {
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            return null;
        }
        $repositoryDir = $projectRoot . '/repository';
        $escapedRepoDir = escapeshellarg($repositoryDir);

        // Check whether the user has a Git upstream set to a Platform
        // environment ID.
        $remote = trim($this->shellExec("cd $escapedRepoDir && git rev-parse --abbrev-ref --symbolic-full-name @{u}"));
        if ($remote && strpos($remote, '/') !== false) {
            list($remoteName, $potentialEnvironment) = explode('/', $remote, 2);
            $environment = $this->getEnvironment($potentialEnvironment, $project);
            if ($environment) {
                // Check that the remote is Platform's.
                $remoteUrl = trim($this->shellExec("cd $escapedRepoDir && git config --get remote.$remoteName.url"));
                if (strpos($remoteUrl, 'platform.sh')) {
                    return $environment;
                }
            }
        }

        // There is no Git remote set, or it's set to a non-Platform URL.
        // Fall back to trying the current branch name.
        $currentBranch = trim($this->shellExec("cd $escapedRepoDir && git symbolic-ref --short HEAD"));
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
     * The current directory tree is traversed until the file is found, or
     * the home directory is reached.
     */
    protected function getProjectRoot()
    {
        $homeDir = $this->getHomeDirectory();
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
            if ($currentDir == $homeDir) {
                // We've reached the home directory, stop.
                break;
            }
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
    protected function getProjects($refresh = false)
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
    protected function getEnvironments($project, $refresh = false, $updateAliases = true)
    {
        $projectId = $project['id'];
        $this->loadConfig();
        if (!isset($this->config['environments'][$projectId]) || $refresh) {
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
                $this->config['environments'][$projectId] = $environments;
                $this->createDrushAliases($project, $environments);
            }
        }

        return $this->config['environments'][$projectId];
    }

    /**
     * Get a single environment.
     *
     * @param string $id The environment ID to load.
     * @param array $project The project.
     *
     * @return array|null The environment, or null if not found.
     */
    protected function getEnvironment($id, $project = null)
    {
        $project = $project ?: $this->getCurrentProject();
        $environments = $this->getEnvironments($project, false);
        if (!isset($environments[$id])) {
            // The list of environments is cached and might be older than the
            // requested environment, so refresh it as a precaution.
            $environments = $this->getEnvironments($project, true);
        }
        return isset($environments[$id]) ? $environments[$id] : null;
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
     * Create drush aliases for the provided project and environments.
     *
     * @param array $project The project
     * @param array $environments The environments
     */
    protected function createDrushAliases($project, $environments)
    {
        // Fail if there is no project root, or if it doesn't contain a Drupal
        // application.
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot || !Drupal::isDrupal($projectRoot . '/repository')) {
            return false;
        }

        $group = $project['id'];
        if (!empty($project['alias-group'])) {
          $group = $project['alias-group'];
        }

        // Ensure the existence of the .drush directory.
        $drushDir = $this->getHomeDirectory() . '/.drush';
        if (!is_dir($drushDir)) {
            mkdir($drushDir);
        }
        $filename = $drushDir . '/' . $group . '.aliases.drushrc.php';

        $export = "<?php\n\n";
        $has_valid_environment = false;
        foreach ($environments as $environment) {
            if (isset($environment['_links']['ssh'])) {
                $sshUrl = parse_url($environment['_links']['ssh']['href']);
                $alias = array(
                  'parent' => '@parent',
                  'uri' => $environment['_links']['public-url']['href'],
                  'site' => $project['id'],
                  'env' => $environment['id'],
                  'remote-host' => $sshUrl['host'],
                  'remote-user' => $sshUrl['user'],
                  'root' => '/app/public',
                );
                $export .= "\$aliases['" . $environment['id'] . "'] = " . var_export($alias, true);
                $export .= ";\n";
                $has_valid_environment = true;
            }
        }

        // Add a local alias as well.
        if ($projectRoot) {
            $wwwRoot = $projectRoot . '/www';
            if (is_dir($wwwRoot)) {
                $local = array(
                  'parent' => '@parent',
                  'site' => $project['id'],
                  'env' => '_local',
                  'root' => $wwwRoot,
                );
                $export .= "\$aliases['_local'] = " . var_export($local, true);
                $export .= ";\n";
                $has_valid_environment = true;
            }
        }

        if ($has_valid_environment) {
            file_put_contents($filename, $export);
        }
        else {
            // Ensure the file doesn't exist.
            if (file_exists($filename)) {
                unlink($filename);
            }
        }

    }

    /**
     * Ask the user to confirm an action.
     *
     * @param string $questionText
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param bool $default
     *
     * @return bool
     */
    protected function confirm($questionText, InputInterface $input, OutputInterface $output, $default = true) {
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion($questionText, $default);
        return $helper->ask($input, $output, $question);
    }

    /**
     * Delete a directory and all of its files.
     *
     * @param string $directory
     */
    protected function rmdir($directory)
    {
        if (is_dir($directory)) {
            // Recursively empty the directory.
            $directoryResource = opendir($directory);
            while ($file = readdir($directoryResource)) {
                if (!in_array($file, array('.', '..'))) {
                    if (is_link($directory . '/' . $file)) {
                        unlink($directory . '/' . $file);
                    } else if (is_dir($directory . '/' . $file)) {
                        $this->rmdir($directory . '/' . $file);
                    } else {
                        unlink($directory . '/' . $file);
                    }
                }
            }
            closedir($directoryResource);

            // Delete the directory itself.
            rmdir($directory);
        }
    }

    /**
     * Run a shell command in the current directory, suppressing errors.
     *
     * @param string $cmd The command, suitably escaped.
     * @param string &$error Optionally use this to capture errors.
     *
     * @throws \Exception
     *
     * @return string The command output.
     */
    protected function shellExec($cmd, &$error = '')
    {
      $descriptorSpec = array(
        0 => array('pipe', 'r'), // stdin
        1 => array('pipe', 'w'), // stdout
        2 => array('pipe', 'w'), // stderr
      );
      $process = proc_open($cmd, $descriptorSpec, $pipes);
      if (!$process) {
          throw new \Exception('Failed to execute command');
      }
      $result = stream_get_contents($pipes[1]);
      $error = stream_get_contents($pipes[2]);
      fclose($pipes[1]);
      fclose($pipes[2]);
      proc_close($process);
      return $result;
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
     * Destructor: Write the configuration to disk.
     */
    public function __destruct()
    {
        if (is_array($this->config)) {
            if ($this->oauth2Plugin) {
                // Save the access token for future requests.
                $this->config['access_token'] = $this->oauth2Plugin->getAccessToken();
            }

            $configPath = $this->getHomeDirectory() . '/.platform';
            $dumper = new Dumper();
            file_put_contents($configPath, $dumper->dump($this->config));
        }
    }

    /**
     * @return string The absolute path to the user's home directory.
     */
    public function getHomeDirectory()
    {
        $home = getenv('HOME');
        if (empty($home)) {
            // Windows compatibility.
            if (!empty($_SERVER['HOMEDRIVE']) && !empty($_SERVER['HOMEPATH'])) {
                $home = $_SERVER['HOMEDRIVE'] . $_SERVER['HOMEPATH'];
            }
        }

        return $home;
    }
}
