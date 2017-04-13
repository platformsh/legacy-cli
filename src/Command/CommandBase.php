<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Event\EnvironmentsChangedEvent;
use Platformsh\Cli\Exception\LoginRequiredException;
use Platformsh\Cli\Exception\ProjectNotFoundException;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException as ConsoleInvalidArgumentException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

abstract class CommandBase extends Command implements CanHideInListInterface, MultiAwareInterface
{
    use HasExamplesTrait;

    /** @var bool */
    private static $checkedUpdates;

    /**
     * @see self::getProjectRoot()
     * @see self::setProjectRoot()
     *
     * @var string|false|null
     */
    private static $projectRoot = null;

    /** @var OutputInterface|null */
    protected $stdErr;

    protected $envArgName = 'environment';
    protected $hiddenInList = false;
    protected $local = false;
    protected $canBeRunMultipleTimes = true;
    protected $runningViaMulti = false;

    private static $container;

    /** @var \Platformsh\Cli\Service\Api|null */
    private $api;

    /** @var InputInterface|null */
    private $input;

    /** @var OutputInterface|null */
    private $output;

    /**
     * @see self::setHiddenAliases()
     *
     * @var array
     */
    private $hiddenAliases = [];

    /**
     * The project, selected either by an option or the CWD.
     *
     * @var Project|false
     */
    private $project;

    /**
     * The current project, based on the CWD.
     *
     * @var Project|false|null
     */
    private $currentProject;

    /**
     * The environment, selected by an option, an argument, or the CWD.
     *
     * @var Environment|false
     */
    private $environment;

    /**
     * The command synopsis.
     *
     * @var array
     */
    private $synopsis = [];

    /**
     * {@inheritdoc}
     */
    public function isHiddenInList()
    {
        return $this->hiddenInList;
    }

    /**
     * @inheritdoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        // Set up dependencies that are only needed once per command run.
        $this->output = $output;
        $this->container()->set('output', $output);
        $this->input = $input;
        $this->container()->set('input', $input);
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        if ($this->config()->get('api.debug')) {
            $output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
        }

        // Tune error reporting based on the output verbosity.
        ini_set('log_errors', 0);
        ini_set('display_errors', 0);
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
        } elseif ($output->getVerbosity() === OutputInterface::VERBOSITY_QUIET) {
            error_reporting(false);
        } else {
            error_reporting(E_PARSE | E_ERROR);
        }

        $this->promptLegacyMigrate();
    }

    /**
     * Set up the API object.
     *
     * @return \Platformsh\Cli\Service\Api
     */
    protected function api()
    {
        if (!isset($this->api)) {
            $this->api = $this->getService('api');
            $this->api
                ->dispatcher
                ->addListener('login_required', [$this, 'login']);
            $this->api
                ->dispatcher
                ->addListener('environments_changed', [$this, 'updateDrushAliases']);
        }

        return $this->api;
    }

    /**
     * Prompt the user to migrate from the legacy project file structure.
     *
     * If the input is interactive, the user will be asked to migrate up to once
     * per hour. The time they were last asked will be stored in the project
     * configuration. If the input is not interactive, the user will be warned
     * (on every command run) that they should run the 'legacy-migrate' command.
     */
    private function promptLegacyMigrate()
    {
        static $asked = false;
        /** @var \Platformsh\Cli\Local\LocalProject $localProject */
        $localProject = $this->getService('local.project');
        if ($localProject->getLegacyProjectRoot() && $this->getName() !== 'legacy-migrate' && !$asked) {
            $asked = true;

            $projectRoot = $this->getProjectRoot();
            $timestamp = time();
            $promptMigrate = true;
            if ($projectRoot) {
                $projectConfig = $localProject->getProjectConfig($projectRoot);
                if (isset($projectConfig['migrate']['3.x']['last_asked'])
                    && $projectConfig['migrate']['3.x']['last_asked'] > $timestamp - 3600) {
                    $promptMigrate = false;
                }
            }

            $this->stdErr->writeln(sprintf(
                'You are in a project using an old file structure, from previous versions of the %s.',
                $this->config()->get('application.name')
            ));
            if ($this->input->isInteractive() && $promptMigrate) {
                if ($projectRoot && isset($projectConfig)) {
                    $projectConfig['migrate']['3.x']['last_asked'] = $timestamp;
                    $localProject->writeCurrentProjectConfig($projectConfig, $projectRoot);
                }
                /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
                $questionHelper = $this->getService('question_helper');
                if ($questionHelper->confirm('Migrate to the new structure?')) {
                    $code = $this->runOtherCommand('legacy-migrate');
                    exit($code);
                }
            } else {
                $this->stdErr->writeln(sprintf(
                    'Fix this with: <comment>%s legacy-migrate</comment>',
                    $this->config()->get('application.executable')
                ));
            }
            $this->stdErr->writeln('');
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function interact(InputInterface $input, OutputInterface $output)
    {
        // Work around a bug in Console which means the default command's input
        // is always considered to be interactive.
        if ($this->getName() === 'welcome'
            && isset($GLOBALS['argv'])
            && array_intersect($GLOBALS['argv'], ['-n', '--no', '-y', '---yes'])) {
            $input->setInteractive(false);
            return;
        }

        $this->checkUpdates();
    }

    /**
     * Check for updates.
     *
     * @param bool $reset
     */
    protected function checkUpdates($reset = false)
    {
        if (!$reset && self::$checkedUpdates) {
            return;
        }
        self::$checkedUpdates = true;

        // Check that this instance of the CLI was installed as a Phar.
        if (!extension_loaded('Phar') || !\Phar::running(false)) {
            return;
        }

        $timestamp = time();
        $config = $this->config();

        if (!$config->get('updates.check')) {
            return;
        } elseif (!$reset
            && $config->get('updates.last_checked') > $timestamp - $config->get('updates.check_interval')) {
            return;
        }

        $config->writeUserConfig([
            'updates' => [
                'check' => true,
                'last_checked' => $timestamp,
            ],
        ]);

        // Ensure classes are auto-loaded if they may be needed after the
        // update.
        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $currentVersion = $this->config()->get('application.version');

        /** @var \Platformsh\Cli\Service\SelfUpdater $cliUpdater */
        $cliUpdater = $this->getService('self_updater');
        $cliUpdater->setAllowMajor(true);
        $cliUpdater->setTimeout(10);

        try {
            $newVersion = $cliUpdater->update(null, $currentVersion);
        } catch (\RuntimeException $e) {
            if (strpos($e->getMessage(), 'Failed to download') !== false) {
                $this->stdErr->writeln('<error>' . $e->getMessage() . '</error>');
                $newVersion = false;
            } else {
                throw $e;
            }
        }

        // If the update was successful, and it's not a major version change,
        // then prompt the user to continue after updating.
        if ($newVersion !== false) {
            $exitCode = 0;
            list($currentMajorVersion,) = explode('.', $currentVersion, 2);
            list($newMajorVersion,) = explode('.', $newVersion, 2);
            if ($newMajorVersion === $currentMajorVersion && isset($GLOBALS['argv'])) {
                $originalCommand = implode(' ', array_map('escapeshellarg', $GLOBALS['argv']));
                $questionText = "\n"
                    . 'Original command: <info>' . $originalCommand . '</info>'
                    . "\n\n" . 'Continue?';
                if ($questionHelper->confirm($questionText)) {
                    $this->stdErr->writeln('');
                    $exitCode = $shell->executeSimple($originalCommand);
                }
            }
            exit($exitCode);
        }

        $this->stdErr->writeln('');
    }

    /**
     * Log in the user.
     *
     * This is called via the 'login_required' event.
     *
     * @see Api::getClient()
     */
    public function login()
    {
        if (!$this->output || (isset($this->input) && !$this->input->isInteractive())) {
            throw new LoginRequiredException();
        }
        $exitCode = $this->runOtherCommand('login');
        $this->stdErr->writeln('');
        if ($exitCode) {
            throw new \Exception('Login failed');
        }
    }

    /**
     * Is this a local command? (if it does not make API requests)
     *
     * @return bool
     */
    public function isLocal()
    {
        return $this->local;
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
        if (isset($this->currentProject)) {
            return $this->currentProject;
        }
        if (!$projectRoot = $this->getProjectRoot()) {
            return false;
        }

        $project = false;
        /** @var \Platformsh\Cli\Local\LocalProject $localProject */
        $localProject = $this->getService('local.project');
        $config = $localProject->getProjectConfig($projectRoot);
        if ($config) {
            $project = $this->api()->getProject($config['id'], isset($config['host']) ? $config['host'] : null);
            // There is a chance that the project isn't available.
            if (!$project) {
                if (isset($config['host'])) {
                    $projectUrl = sprintf('https://%s/projects/%s', $config['host'], $config['id']);
                    $message = "Project not found: " . $projectUrl
                        . "\nThe project probably no longer exists.";
                } else {
                    $message = "Project not found: " . $config['id']
                        . "\nEither you do not have access to the project or it no longer exists.";
                }
                throw new ProjectNotFoundException($message);
            }
            $this->debug('Project ' . $config['id'] . ' is mapped to the current directory');
        }
        $this->currentProject = $project;

        return $project;
    }

    /**
     * Get the current environment if the user is in a project directory.
     *
     * @param Project $expectedProject The expected project.
     * @param bool|null $refresh Whether to refresh the environments or projects
     *                           cache.
     *
     * @return Environment|false The current environment.
     */
    public function getCurrentEnvironment(Project $expectedProject = null, $refresh = null)
    {
        if (!($projectRoot = $this->getProjectRoot())
            || !($project = $this->getCurrentProject())
            || ($expectedProject !== null && $expectedProject->id !== $project->id)) {
            return false;
        }

        /** @var \Platformsh\Cli\Service\Git $git */
        $git = $this->getService('git');
        $git->setDefaultRepositoryDir($this->getProjectRoot());
        /** @var \Platformsh\Cli\Local\LocalProject $localProject */
        $localProject = $this->getService('local.project');
        $config = $localProject->getProjectConfig($projectRoot);

        // Check if there is a manual mapping set for the current branch.
        if (!empty($config['mapping'])
            && ($currentBranch = $git->getCurrentBranch())
            && !empty($config['mapping'][$currentBranch])) {
            $environment = $this->api()->getEnvironment($config['mapping'][$currentBranch], $project, $refresh);
            if ($environment) {
                $this->debug('Found mapped environment for branch ' . $currentBranch . ': ' . $environment->id);
                return $environment;
            } else {
                unset($config['mapping'][$currentBranch]);
                $localProject->writeCurrentProjectConfig($config, $projectRoot);
            }
        }

        // Check whether the user has a Git upstream set to a remote environment
        // ID.
        $upstream = $git->getUpstream();
        if ($upstream && strpos($upstream, '/') !== false) {
            list(, $potentialEnvironment) = explode('/', $upstream, 2);
            $environment = $this->api()->getEnvironment($potentialEnvironment, $project, $refresh);
            if ($environment) {
                $this->debug('Selected environment ' . $potentialEnvironment . ', based on Git upstream: ' . $upstream);
                return $environment;
            }
        }

        // There is no Git remote set. Fall back to trying the current branch
        // name.
        if (!empty($currentBranch) || ($currentBranch = $git->getCurrentBranch())) {
            $environment = $this->api()->getEnvironment($currentBranch, $project, $refresh);
            if (!$environment) {
                // Try a sanitized version of the branch name too.
                $currentBranchSanitized = Environment::sanitizeId($currentBranch);
                if ($currentBranchSanitized !== $currentBranch) {
                    $environment = $this->api()->getEnvironment($currentBranchSanitized, $project, $refresh);
                }
            }
            if ($environment) {
                $this->debug('Selected environment ' . $environment->id . ' based on branch name: ' . $currentBranch);
                return $environment;
            }
        }

        return false;
    }

    /**
     * Update the user's local Drush aliases.
     *
     * This is called via the 'environments_changed' event.
     *
     * @see \Platformsh\Cli\Service\Api::getEnvironments()
     *
     * @param EnvironmentsChangedEvent $event
     */
    public function updateDrushAliases(EnvironmentsChangedEvent $event)
    {
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            return;
        }
        // Double-check that the passed project is the current one.
        $currentProject = $this->getCurrentProject();
        if (!$currentProject || $currentProject->id != $event->getProject()->id) {
            return;
        }
        // Ignore the project if it doesn't contain a Drupal application.
        if (!Drupal::isDrupal($projectRoot)) {
            return;
        }
        $this->debug('Updating Drush aliases');
        /** @var \Platformsh\Cli\Service\Drush $drush */
        $drush = $this->getService('drush');
        $drush->createAliases($event->getProject(), $projectRoot, $event->getEnvironments());
    }

    /**
     * @param string $root
     */
    protected function setProjectRoot($root)
    {
        if (!is_dir($root)) {
            throw new \InvalidArgumentException("Invalid project root: $root");
        }
        self::$projectRoot = $root;
    }

    /**
     * @return string|false
     */
    public function getProjectRoot()
    {
        if (!isset(self::$projectRoot)) {
            $this->debug('Finding the project root');
            /** @var \Platformsh\Cli\Local\LocalProject $localProject */
            $localProject = $this->getService('local.project');
            self::$projectRoot = $localProject->getProjectRoot();
            $this->debug(
                self::$projectRoot
                    ? 'Project root found: ' . self::$projectRoot
                    : 'Project root not found'
            );
        }

        return self::$projectRoot;
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
        $this->stdErr->writeln([
            '<comment>The remote environment must be rebuilt for the change to take effect.</comment>',
            "Use 'git push' with new commit(s) to trigger a rebuild."
        ]);
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
     * @return CommandBase
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
     * @return CommandBase
     */
    protected function addEnvironmentOption()
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->addOption('environment', 'e', InputOption::VALUE_REQUIRED, 'The environment ID');
    }

    /**
     * Add the --app option.
     *
     * @return CommandBase
     */
    protected function addAppOption()
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->addOption('app', 'A', InputOption::VALUE_REQUIRED, 'The remote application name');
    }

    /**
     * Add the --no-wait option.
     *
     * @param string $description
     *
     * @return CommandBase
     */
    protected function addNoWaitOption($description = 'Do not wait for the operation to complete')
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->addOption('no-wait', null, InputOption::VALUE_NONE, $description);
    }

    /**
     * Select the project for the user, based on input or the environment.
     *
     * @param string $projectId
     * @param string $host
     *
     * @return Project
     */
    protected function selectProject($projectId = null, $host = null)
    {
        if (!empty($projectId)) {
            $project = $this->api()->getProject($projectId, $host);
            if (!$project) {
                throw new ConsoleInvalidArgumentException('Specified project not found: ' . $projectId);
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

        $this->project = $project;

        return $this->project;
    }

    /**
     * Select the current environment for the user.
     *
     * @throws \RuntimeException If the current environment cannot be found.
     *
     * @param string|null $environmentId
     *   The environment ID specified by the user, or null to auto-detect the
     *   environment.
     * @param bool $required
     *   Whether it's required to have an environment.
     */
    protected function selectEnvironment($environmentId = null, $required = true)
    {
        if (!empty($environmentId)) {
            $environment = $this->api()->getEnvironment($environmentId, $this->project, null, true);
            if (!$environment) {
                throw new ConsoleInvalidArgumentException('Specified environment not found: ' . $environmentId);
            }

            $this->environment = $environment;
            return;
        }

        // If no ID is specified, try to auto-detect the current environment.
        if ($environment = $this->getCurrentEnvironment($this->project)) {
            $this->environment = $environment;
            return;
        }

        if ($required) {
            if ($this->getProjectRoot()) {
                $message = 'Could not determine the current environment.'
                    . "\n" . 'Specify it manually using --environment (-e).';
            } else {
                $message = 'No environment specified.'
                    . "\n" . 'Specify one using --environment (-e), or go to a project directory.';
            }
            throw new ConsoleInvalidArgumentException($message);
        }
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
        if (!$projectRoot || !$this->selectedProjectIsCurrent()) {
            return null;
        }

        $this->debug('Searching for applications in local repository');
        /** @var LocalApplication[] $apps */
        $apps = LocalApplication::getApplications($projectRoot, $this->config());

        if ($filter) {
            $apps = array_filter($apps, $filter);
        }

        if (count($apps) > 1 && $input->isInteractive()) {
            /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');
            $choices = [];
            foreach ($apps as $app) {
                $choices[$app->getName()] = $app->getName();
            }
            $appName = $questionHelper->choose($choices, 'Enter a number to choose an app:');
        }

        $input->setOption('app', $appName);

        return $appName;
    }

    /**
     * Parse the project ID and possibly other details from a provided URL.
     *
     * @param string $url
     *     A web UI, API, or public URL of the project.
     *
     * @throws \InvalidArgumentException
     *     If the project ID can't be found in the URL.
     *
     * @return array
     *     An array of containing at least a 'projectId'. Keys 'host',
     *     'environmentId', and 'appId' will be either null or strings.
     */
    protected function parseProjectId($url)
    {
        $result = [
            'projectId' => null,
            'host' => null,
            'environmentId' => null,
            'appId' => null,
        ];

        // If it's a plain alphanumeric string, then it's an ID already.
        if (!preg_match('/\W/', $url)) {
            $result['projectId'] = $url;

            return $result;
        }

        $this->debug('Parsing URL to determine project ID');

        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);
        $site_domains_pattern = '(' . implode('|', array_map('preg_quote', $this->config()->get('detection.site_domains'))) . ')';
        $site_pattern = '/\-\w+\.[a-z]{2}(\-[0-9])?\.' . $site_domains_pattern . '$/';
        if (!strpos($path, '/projects/')
            && preg_match($site_pattern, $host)) {
            list($env_project_app,) = explode('.', $host, 2);
            if (($tripleDashPos = strrpos($env_project_app, '---')) !== false) {
                $env_project_app = substr($env_project_app, $tripleDashPos + 3);
            }
            if (($doubleDashPos = strrpos($env_project_app, '--')) !== false) {
                $env_project = substr($env_project_app, 0, $doubleDashPos);
                $result['appId'] = substr($env_project_app, $doubleDashPos + 2);
            } else {
                $env_project = $env_project_app;
            }
            if (($dashPos = strrpos($env_project, '-')) !== false) {
                $result['projectId'] = substr($env_project, $dashPos + 1);
                $result['environmentId'] = substr($env_project, 0, $dashPos);
            }
        } else {
            $result['host'] = $host;
            $result['projectId'] = basename(preg_replace('#/projects(/\w+)/?.*$#', '$1', $url));
            if (preg_match('#/environments(/[^/]+)/?.*$#', $url, $matches)) {
                $result['environmentId'] = rawurldecode(basename($matches[1]));
            }
        }
        if (empty($result['projectId']) || preg_match('/\W/', $result['projectId'])) {
            throw new ConsoleInvalidArgumentException(sprintf('Invalid project URL: %s', $url));
        }

        return $result;
    }

    /**
     * @param InputInterface  $input
     * @param bool $envNotRequired
     */
    protected function validateInput(InputInterface $input, $envNotRequired = false)
    {
        $projectId = $input->hasOption('project') ? $input->getOption('project') : null;
        $projectHost = $input->hasOption('host') ? $input->getOption('host') : null;
        $environmentId = null;

        // Parse the project ID.
        if ($projectId !== null) {
            $result = $this->parseProjectId($projectId);
            $projectId = $result['projectId'];
            $projectHost = $projectHost ?: $result['host'];
            $environmentId = $result['environmentId'];
        }

        // Set the --app option based on the parsed project URL, if relevant.
        if (isset($result['appId']) && $input->hasOption('app') && !$input->getOption('app')) {
            $input->setOption('app', $result['appId']);
        }

        // Select the project.
        $this->selectProject($projectId, $projectHost);

        // Select the environment.
        $envOptionName = 'environment';
        if ($input->hasArgument($this->envArgName) && $input->getArgument($this->envArgName)) {
            if ($input->hasOption($envOptionName) && $input->getOption($envOptionName)) {
                throw new ConsoleInvalidArgumentException(
                    sprintf(
                        'You cannot use both the <%s> argument and the --%s option',
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
                $this->debug('Selecting environment based on input argument');
                $this->selectEnvironment($argument);
            }
        } elseif ($input->hasOption($envOptionName)) {
            $environmentId = $input->getOption($envOptionName) ?: $environmentId;
            $this->selectEnvironment($environmentId, !$envNotRequired);
        }

        $this->debug('Validated input');
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
     * @param OutputInterface $output
     *   The output for the other command. Defaults to the current output.
     *
     * @return int
     */
    protected function runOtherCommand($name, array $arguments = [], OutputInterface $output = null)
    {
        /** @var \Platformsh\Cli\Application $application */
        $application = $this->getApplication();
        $command = $application->find($name);

        // Pass on interactivity arguments to the other command.
        if (isset($this->input)) {
            $arguments += [
                '--yes' => $this->input->getOption('yes'),
                '--no' => $this->input->getOption('no'),
            ];
        }

        $cmdInput = new ArrayInput(['command' => $name] + $arguments);
        if (!empty($arguments['--yes']) || !empty($arguments['--no'])) {
            $cmdInput->setInteractive(false);
        } elseif (isset($this->input)) {
            $cmdInput->setInteractive($this->input->isInteractive());
        }

        $this->debug('Running command: ' . $name);

        $this->container()->reset();

        $application->setCurrentCommand($command);
        $result = $command->run($cmdInput, $output ?: $this->output);
        $application->setCurrentCommand($this);

        return $result;
    }

    /**
     * Add aliases that should be hidden from help.
     *
     * @see parent::setAliases()
     *
     * @param array $hiddenAliases
     *
     * @return CommandBase
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

    /**
     * {@inheritdoc}
     *
     * Overrides the default method so that the description is not repeated
     * twice.
     */
    public function getProcessedHelp()
    {
        $help = $this->getHelp();
        if ($help === '') {
            return $help;
        }
        $name = $this->getName();

        $placeholders = ['%command.name%', '%command.full_name%'];
        $replacements = [$name, $this->config()->get('application.executable') . ' ' . $name];

        return str_replace($placeholders, $replacements, $help);
    }

    /**
     * Print a message if debug output is enabled.
     *
     * @param string $message
     */
    protected function debug($message)
    {
        if (isset($this->stdErr)) {
            $this->stdErr->writeln('<options=reverse>DEBUG</> ' . $message, OutputInterface::VERBOSITY_DEBUG);
        }
    }

    /**
     * Get a service object.
     *
     * Services are configured in services.yml, and loaded via the Symfony
     * Dependency Injection component.
     *
     * @param string $name The service name. See services.yml for a list.
     *
     * @return object The associated service object.
     */
    protected function getService($name)
    {
        return $this->container()->get($name);
    }

    /**
     * @return ContainerBuilder
     */
    private function container()
    {
        if (!isset(self::$container)) {
            self::$container = new ContainerBuilder();
            $loader = new YamlFileLoader(self::$container, new FileLocator());
            $loader->load(CLI_ROOT . '/services.yaml');
        }

        return self::$container;
    }

    /**
     * Get the configuration service.
     *
     * @return \Platformsh\Cli\Service\Config
     */
    protected function config()
    {
        static $config;
        if (!isset($config)) {
            /** @var \Platformsh\Cli\Service\Config $config */
            $config = $this->getService('config');
        }

        return $config;
    }

    /**
     * {@inheritdoc}
     */
    public function canBeRunMultipleTimes()
    {
        return $this->canBeRunMultipleTimes;
    }

    /**
     * {@inheritdoc}
     */
    public function setRunningViaMulti($runningViaMulti = true)
    {
        $this->runningViaMulti = $runningViaMulti;
    }

    /**
     * {@inheritdoc}
     */
    public function getSynopsis($short = false)
    {
        $key = $short ? 'short' : 'long';

        if (!isset($this->synopsis[$key])) {
            $aliases = $this->getVisibleAliases();
            $name = $this->getName();
            $shortName = count($aliases) === 1 ? reset($aliases) : $name;
            $this->synopsis[$key] = trim(sprintf(
                '%s %s %s',
                $this->config()->get('application.executable'),
                $shortName,
                $this->getDefinition()->getSynopsis($short)
            ));
        }

        return $this->synopsis[$key];
    }
}
