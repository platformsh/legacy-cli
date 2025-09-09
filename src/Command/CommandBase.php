<?php

namespace Platformsh\Cli\Command;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\Self\SelfInstallCommand;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Console\HiddenInputOption;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Cli\Event\EnvironmentsChangedEvent;
use Platformsh\Cli\Event\LoginRequiredEvent;
use Platformsh\Cli\Exception\LoginRequiredException;
use Platformsh\Cli\Exception\NoOrganizationsException;
use Platformsh\Cli\Exception\ProjectNotFoundException;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Model\Host\HostInterface;
use Platformsh\Cli\Model\Host\LocalHost;
use Platformsh\Cli\Model\Host\RemoteHost;
use Platformsh\Cli\Model\RemoteContainer;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Cli\Util\StringUtil;
use Platformsh\Client\Exception\EnvironmentStateException;
use Platformsh\Client\Model\BasicProjectInfo;
use Platformsh\Client\Model\Deployment\EnvironmentDeployment;
use Platformsh\Client\Model\Deployment\Service;
use Platformsh\Client\Model\Deployment\WebApp;
use Platformsh\Client\Model\Deployment\Worker;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Organization\Organization;
use Platformsh\Client\Model\Project;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException as ConsoleInvalidArgumentException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

abstract class CommandBase extends Command implements MultiAwareInterface
{
    use HasExamplesTrait;

    const STABILITY_STABLE = 'STABLE';
    const STABILITY_BETA = 'BETA';
    const STABILITY_DEPRECATED = 'DEPRECATED';

    const DEFAULT_ENVIRONMENT_CODE = '.';

    /** @var ?bool */
    private static $checkedUpdates;
    /** @var ?bool */
    private static $checkedSelfInstall;
    /** @var ?bool */
    private static $checkedMigrate;
    /** @var ?bool */
    private static $promptedDeleteOldCli;

    /** @var ?bool */
    private static $printedApiTokenWarning;

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
    protected $chooseProjectText = 'Enter a number to choose a project:';
    protected $chooseEnvText = 'Enter a number to choose an environment:';
    protected $enterProjectText = 'Enter a project ID';
    protected $enterEnvText = 'Enter an environment ID';
    protected $chooseEnvFilter;
    protected $hiddenInList = false;
    protected $stability = self::STABILITY_STABLE;
    protected $local = false;
    protected $canBeRunMultipleTimes = true;
    protected $runningViaMulti = false;

    /**
     * Whether the selected project has been printed (e.g. via verbose output).
     *
     * @var bool
     */
    protected $printedSelectedProject = false;

    /**
     * Whether the selected environment has been printed (e.g. via verbose output).
     *
     * @var bool
     */
    protected $printedSelectedEnvironment = false;

    /**
     * @var string[] The valid values for --resources-init on this command.
     *
     * @see CommandBase::addResourcesInitOption()
     */
    protected $validResourcesInitValues = [];

    private static $container;

    /** @var \Platformsh\Cli\Service\Api|null */
    private $api;
    /** @var ?bool */
    private $apiHasListeners;

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
     * The remote container, selected via the --app and --worker options.
     *
     * @var RemoteContainer\RemoteContainerInterface
     */
    private $remoteContainer;

    /**
     * The command synopsis.
     *
     * @var array
     */
    private $synopsis = [];

    /**
     * {@inheritdoc}
     */
    public function isHidden()
    {
        return $this->hiddenInList
            || !in_array($this->stability, [self::STABILITY_STABLE, self::STABILITY_BETA])
            || $this->config()->isCommandHidden($this->getName());
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

        // Clear cache properties, in case this object is being reused with
        // separate input.
        $this->project = null;
        $this->environment = null;
        $this->remoteContainer = null;

        $this->promptLegacyMigrate();

        if (!self::$printedApiTokenWarning && $this->onContainer() && (getenv($this->config()->get('application.env_prefix') . 'TOKEN') || $this->api()->hasApiToken(false))) {
            $this->stdErr->writeln('<fg=yellow;options=bold>Warning:</>');
            $this->stdErr->writeln('<fg=yellow>An API token is set. Anyone with SSH access to this environment can read the token.</>');
            $this->stdErr->writeln('<fg=yellow>Please ensure the token only has strictly necessary access.</>');
            $this->stdErr->writeln('');
            self::$printedApiTokenWarning = true;
        }
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
        }
        if (!$this->apiHasListeners && $this->output && $this->input) {
            $this->api
                ->dispatcher
                ->addListener('login_required', [$this, 'login']);
            if ($this->config()->get('application.drush_aliases')) {
                $this->api
                    ->dispatcher
                    ->addListener('environments_changed', [$this, 'updateDrushAliases']);
            }
            $this->apiHasListeners = true;
        }

        return $this->api;
    }

    /**
     * Detects if the command is running on an application container.
     *
     * @return bool
     */
    private function onContainer() {
        $envPrefix = $this->config()->get('service.env_prefix');
        return getenv($envPrefix . 'PROJECT') !== false
            && getenv($envPrefix . 'BRANCH') !== false
            && getenv($envPrefix . 'TREE_ID') !== false;
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
        $this->checkSelfInstall();
        // Run migration steps if configured.
        if ($this->config()->getWithDefault('migrate.prompt', false)) {
            $this->promptDeleteOldCli();
            $this->checkMigrateToNewCLI();
        }
    }

    /**
     * Check for self-installation.
     */
    protected function checkSelfInstall()
    {
        // Avoid checking more than once in this process.
        if (self::$checkedSelfInstall) {
            return;
        }
        self::$checkedSelfInstall = true;

        $config = $this->config();

        // Avoid if disabled.
        if (!$config->getWithDefault('application.prompt_self_install', true)
            || !$config->isCommandEnabled('self:install')) {
            return;
        }

        // Avoid if already installed.
        if (file_exists($config->getUserConfigDir() . DIRECTORY_SEPARATOR . SelfInstallCommand::INSTALLED_FILENAME)) {
            return;
        }

        // Avoid if other CLIs are installed.
        if ($this->isWrapped() || $this->otherCLIsInstalled()) {
            return;
        }

        // Stop if already prompted and declined.
        /** @var \Platformsh\Cli\Service\State $state */
        $state = $this->getService('state');
        if ($state->get('self_install.last_prompted') !== false) {
            return;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $this->stdErr->writeln('CLI resource files can be installed automatically. They provide support for autocompletion and other features.');
        $questionText = 'Do you want to install these files?';
        if (file_exists($config->getUserConfigDir() . DIRECTORY_SEPARATOR . '/shell-config.rc')) {
            $questionText = 'Do you want to complete the installation?';
        }
        $answer = $questionHelper->confirm($questionText);
        $state->set('self_install.last_prompted', time());
        $this->stdErr->writeln('');

        if ($answer) {
            $this->runOtherCommand('self:install');
        } else {
            $this->stdErr->writeln('To install at another time, run: <info>' . $config->get('application.executable') . ' self:install</info>');
        }

        $this->stdErr->writeln('');
    }

    /**
     * Check for updates.
     */
    protected function checkUpdates()
    {
        // Avoid checking more than once in this process.
        if (self::$checkedUpdates) {
            return;
        }
        self::$checkedUpdates = true;

        // Check that the Phar extension is available.
        if (!extension_loaded('Phar')) {
            return;
        }

        // Get the filename of the Phar, or stop if this instance of the CLI is
        // not a Phar.
        $pharFilename = \Phar::running(false);
        if (!$pharFilename) {
            return;
        }

        // Check if the file and its containing directory are writable.
        if (!is_writable($pharFilename) || !is_writable(dirname($pharFilename))) {
            return;
        }

        // Check if updates are configured.
        $config = $this->config();
        if (!$config->getWithDefault('updates.check', true)) {
            return;
        }

        // Determine an embargo time, after which updates can be checked.
        $timestamp = time();
        $embargoTime = $timestamp - (int) $config->getWithDefault('updates.check_interval', 604800);

        // Stop if updates were last checked after the embargo time.
        /** @var \Platformsh\Cli\Service\State $state */
        $state = $this->getService('state');
        if ($state->get('updates.last_checked') > $embargoTime) {
            return;
        }

        // Stop if the Phar was updated after the embargo time.
        if (filemtime($pharFilename) > $embargoTime) {
            return;
        }

        // Ensure classes are auto-loaded if they may be needed after the
        // update.
        $this->getService('question_helper');
        $this->getService('shell');
        $currentVersion = $this->config()->getVersion();

        /** @var \Platformsh\Cli\Service\SelfUpdater $cliUpdater */
        $cliUpdater = $this->getService('self_updater');
        $cliUpdater->setAllowMajor(true);
        $cliUpdater->setTimeout(5);

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

        $state->set('updates.last_checked', $timestamp);

        if ($newVersion === '') {
            // No update was available.
            return;
        }

        if ($newVersion !== false) {
            // Update succeeded. Continue (based on a few conditions).
            $this->continueAfterUpdating($currentVersion, $newVersion, $pharFilename);
            exit(0);
        }

        // Automatic update failed.
        // Error messages will already have been printed, and the original
        // command can continue.
        $this->stdErr->writeln('');
    }

    private function cliPath()
    {
        $thisPath = CLI_ROOT . '/bin/platform';
        if (defined('CLI_FILE')) {
            $thisPath = CLI_FILE;
        }
        if (extension_loaded('Phar') && ($pharPath = \Phar::running(false))) {
            $thisPath = $pharPath;
        }
        return $thisPath;
    }

    /**
     * Returns whether other instances are installed of the CLI.
     *
     * Finds programs with the same executable name in the PATH.
     *
     * @return bool
     */
    private function otherCLIsInstalled()
    {
        static $otherPaths;
        if ($otherPaths === null) {
            $thisPath = $this->cliPath();
            $paths = (new OsUtil())->findExecutables($this->config()->get('application.executable'));
            $otherPaths = array_unique(array_filter($paths, function ($p) use ($thisPath) {
                $realpath = realpath($p);
                return $realpath && $realpath !== $thisPath;
            }));
            if (!empty($otherPaths)) {
                $this->debug('Other CLI(s) found: ' . implode(", ", $otherPaths));
            }
        }
        return !empty($otherPaths);
    }

    /**
     * Check if both CLIs are installed to prompt the user to delete the old one.
     */
    private function promptDeleteOldCli()
    {
        // Avoid checking more than once in this process.
        if (self::$promptedDeleteOldCli) {
            return;
        }
        self::$promptedDeleteOldCli = true;

        if ($this->isWrapped() || !$this->otherCLIsInstalled()) {
            return;
        }
        $pharPath = \Phar::running(false);
        if (!$pharPath || !is_file($pharPath) || !is_writable($pharPath)) {
            return;
        }

        // Avoid deleting random directories in path
        $legacyDir = dirname(dirname($pharPath));
        if ($legacyDir !== $this->config()->getUserConfigDir()) {
            return;
        }

        $message = "\n<comment>Warning:</comment> Multiple CLI instances are installed."
            . "\nThis is probably due to migration between the Legacy CLI and the new CLI."
            . "\nIf so, delete this (Legacy) CLI instance to complete the migration."
            . "\n"
            . "\n<comment>Remove the following file completely</comment>: $pharPath"
            . "\nThis operation is safe and doesn't delete any data."
            . "\n";
        $this->stdErr->writeln($message);
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if ($questionHelper->confirm('Do you want to remove this file now?')) {
            if (unlink($pharPath)) {
                $this->stdErr->writeln('File successfully removed! Open a new terminal for the changes to take effect.');
                // Exit because no further Phar classes can be loaded.
                // This uses a non-zero code because the original command
                // technically failed.
                exit(1);
            } else {
                $this->stdErr->writeln('<error>Error:</error> Failed to delete the file.');
            }
            $this->stdErr->writeln('');
        }
    }

    /**
     * Check for migration to the new CLI.
     */
    protected function checkMigrateToNewCLI()
    {
        // Avoid checking more than once in this process.
        if (self::$checkedMigrate) {
            return;
        }
        self::$checkedMigrate = true;

        // Avoid if running within the new CLI or within a CI.
        if ($this->isWrapped() || $this->isCI()) {
            return;
        }

        $config = $this->config();

        // Prompt the user to migrate at most once every 24 hours.
        $now = time();
        $embargoTime = $now - $config->getWithDefault('migrate.prompt_interval', 60 * 60 * 24);
        $state = $this->getService('state');
        if ($state->get('migrate.last_prompted') > $embargoTime) {
            return;
        }

        $message = "<options=bold;fg=yellow>Warning:</>"
            . "\nRunning the CLI directly under PHP is now referred to as the \"Legacy CLI\", and is no longer recommended.";
        if ($config->has('migrate.docs_url')) {
            $message .= "\nInstall the latest release for your operating system by following these instructions: "
                . "\n" . $config->get('migrate.docs_url');
        }
        $message .= "\n";
        $this->stdErr->writeln($message);
        $state->set('migrate.last_prompted', time());
    }

    /**
     * Prompts the user to continue with the original command after updating.
     *
     * This only applies if it's not a major version change.
     *
     * @param string $currentVersion
     * @param string $newVersion
     * @param string $pharFilename
     *
     * @return void
     */
    private function continueAfterUpdating($currentVersion, $newVersion, $pharFilename) {
        if (!isset($this->input) || !$this->input instanceof ArgvInput || !is_executable($pharFilename)) {
            return;
        }
        list($currentMajorVersion,) = explode('.', ltrim($currentVersion, 'v'), 2);
        list($newMajorVersion,) = explode('.', ltrim($newVersion, 'v'), 2);
        if ($newMajorVersion !== $currentMajorVersion) {
            return;
        }

        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');

        $originalCommand = $this->input->__toString();
        if (empty($originalCommand)) {
            $exitCode = $shell->executeSimple(escapeshellarg($pharFilename));
            exit($exitCode);
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $questionText = "\n"
            . 'Original command: <info>' . $originalCommand . '</info>'
            . "\n\n" . 'Continue?';
        if ($questionHelper->confirm($questionText)) {
            $this->stdErr->writeln('');
            $exitCode = $shell->executeSimple(escapeshellarg($pharFilename) . ' ' . $originalCommand);
            exit($exitCode);
        }
    }

    /**
     * Log in the user.
     *
     * This is called via the 'login_required' event.
     *
     * @param LoginRequiredEvent $event
     * @see Api::getClient()
     */
    public function login(LoginRequiredEvent $event)
    {
        $success = false;
        if ($this->output && $this->input && $this->input->isInteractive()) {
            $sessionAdvice = [];
            if ($this->config()->getSessionId() !== 'default' || count($this->api()->listSessionIds()) > 1) {
                $sessionAdvice[] = sprintf('The current session ID is: <info>%s</info>', $this->config()->getSessionId());
                if (!$this->config()->isSessionIdFromEnv()) {
                    $sessionAdvice[] = sprintf('To switch sessions, run: <info>%s session:switch</info>', $this->config()->get('application.executable'));
                }
            }

            if ($this->config()->getWithDefault('application.login_method', 'browser') === 'browser') {
                /** @var \Platformsh\Cli\Service\Url $url */
                $urlService = $this->getService('url');
                if ($urlService->canOpenUrls()) {
                    $this->stdErr->writeln($event->getMessage());
                    $this->stdErr->writeln('');
                    if ($sessionAdvice) {
                        $this->stdErr->writeln($sessionAdvice);
                        $this->stdErr->writeln('');
                    }
                    /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
                    $questionHelper = $this->getService('question_helper');
                    if ($questionHelper->confirm('Log in via a browser?')) {
                        $this->stdErr->writeln('');
                        $exitCode = $this->runOtherCommand('auth:browser-login', $event->getLoginOptions());
                        $this->stdErr->writeln('');
                        $success = $exitCode === 0;
                    }
                }
            }
        }
        if (!$success) {
            $e = new LoginRequiredException();
            $e->setMessageFromEvent($event);
            throw $e;
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
     * @param bool $suppressErrors Suppress 403 or not found errors.
     *
     * @throws \RuntimeException
     *
     * @return Project|false The current project
     */
    public function getCurrentProject($suppressErrors = false)
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
            $this->debug('Project "' . $config['id'] . '" is mapped to the current directory');
            try {
                $project = $this->api()->getProject($config['id'], isset($config['host']) ? $config['host'] : null);
            } catch (BadResponseException $e) {
                if ($suppressErrors && $e->getResponse() && in_array($e->getResponse()->getStatusCode(), [403, 404])) {
                    return $this->currentProject = false;
                }
                if ($this->config()->has('api.base_url')
                    && $e->getResponse() && $e->getResponse()->getStatusCode() === 401
                    && parse_url($this->config()->get('api.base_url'), PHP_URL_HOST) !== $e->getRequest()->getHost()) {
                    $this->debug('Ignoring 401 error for unrecognized local project hostname: ' . $e->getRequest()->getHost());
                    return $this->currentProject = false;
                }
                throw $e;
            }
            if (!$project) {
                if ($suppressErrors) {
                    return $this->currentProject = false;
                }
                throw new ProjectNotFoundException(
                    "Project not found: " . $config['id']
                    . "\nEither you do not have access to the project or it no longer exists."
                );
            }
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
            || !($project = $this->getCurrentProject(true))
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
                $this->debug('Found mapped environment for branch "' . $currentBranch . '": ' . $this->api()->getEnvironmentLabel($environment));
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
                $this->debug('Selecting environment "' . $environment->id . '" based on Git upstream: ' . $upstream);
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
                $this->debug('Selecting environment "' . $environment->id . '" based on branch name: ' . $currentBranch);
                return $environment;
            }
            $this->debug('No environment was found to match the current Git branch: ' . $currentBranch);
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
        // Make sure the local:drush-aliases command is enabled.
        if (!$this->getApplication()->has('local:drush-aliases')) {
            return;
        }
        // Double-check that the passed project is the current one, and that it
        // still exists.
        $currentProject = $this->getCurrentProject(true);
        if (!$currentProject || $currentProject->id != $event->getProject()->id) {
            return;
        }
        // Ignore the project if it doesn't contain a Drupal application.
        if (!Drupal::isDrupal($projectRoot)) {
            return;
        }
        /** @var \Platformsh\Cli\Service\Drush $drush */
        $drush = $this->getService('drush');
        if ($drush->getVersion() === false) {
            $this->debug('Not updating Drush aliases: the Drush version cannot be determined.');
            return;
        }
        $this->debug('Updating Drush aliases');
        try {
            $drush->createAliases($event->getProject(), $projectRoot, $event->getEnvironments());
        } catch (\Exception $e) {
            $this->stdErr->writeln(sprintf(
                "<comment>Failed to update Drush aliases:</comment>\n%s\n",
                preg_replace('/^/m', '  ', trim($e->getMessage()))
            ));
        }
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
        $current = $this->getCurrentProject(true);
        if (!$current || !$this->hasSelectedProject()) {
            return false;
        }

        return $current->id === $this->getSelectedProject()->id;
    }

    /**
     * Warn the user that the remote environment needs redeploying.
     */
    protected function redeployWarning()
    {
        $this->stdErr->writeln([
            '',
            '<comment>The remote environment(s) must be redeployed for the change to take effect.</comment>',
            'To redeploy an environment, run: <info>' . $this->config()->get('application.executable') . ' redeploy</info>',
        ]);
    }

    /**
     * Adds a hidden command option.
     *
     * @see self::addOption() for the parameters
     *
     * @return self
     */
    protected function addHiddenOption($name, $shortcut = null, $mode = null, $description = '', $default = null)
    {
        $this->getDefinition()->addOption(new HiddenInputOption($name, $shortcut, $mode, $description, $default));

        return $this;
    }

    /**
     * Add the --project and --host options.
     *
     * @return CommandBase
     */
    protected function addProjectOption()
    {
        $this->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'The project ID or URL');
        $this->addHiddenOption('host', null, InputOption::VALUE_REQUIRED, 'Deprecated option, no longer used');

        return $this;
    }

    /**
     * Add the --environment option.
     *
     * @return CommandBase
     */
    protected function addEnvironmentOption()
    {
        return $this->addOption('environment', 'e', InputOption::VALUE_REQUIRED, 'The environment ID. Use "' . self::DEFAULT_ENVIRONMENT_CODE . '" to select the project\'s default environment.');
    }

    /**
     * Add the --app option.
     *
     * @return CommandBase
     */
    protected function addAppOption()
    {
        return $this->addOption('app', 'A', InputOption::VALUE_REQUIRED, 'The remote application name');
    }

    /**
     * Add both the --no-wait and --wait options.
     */
    protected function addWaitOptions()
    {
        $this->addOption('no-wait', 'W', InputOption::VALUE_NONE, 'Do not wait for the operation to complete');
        if ($this->detectRunningInHook()) {
            $this->addOption('wait', null, InputOption::VALUE_NONE, 'Wait for the operation to complete');
        } else {
            $this->addOption('wait', null, InputOption::VALUE_NONE, 'Wait for the operation to complete (default)');
        }
    }

    /**
     * Returns whether we should wait for an operation to complete.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     *
     * @return bool
     */
    protected function shouldWait(InputInterface $input)
    {
        if ($input->hasOption('no-wait') && $input->getOption('no-wait')) {
            return false;
        }
        if ($input->hasOption('wait') && $input->getOption('wait')) {
            return true;
        }
        if ($this->detectRunningInHook()) {
            $serviceName = $this->config()->get('service.name');
            $message = "\n<comment>Warning:</comment> $serviceName hook environment detected: assuming <comment>--no-wait</comment> by default."
                . "\nTo avoid ambiguity, please specify either --no-wait or --wait."
                . "\n";
            $this->stdErr->writeln($message);

            return false;
        }

        return true;
    }

    /**
     * Detects a Platform.sh non-terminal Dash environment; i.e. a hook.
     *
     * @return bool
     */
    protected function detectRunningInHook()
    {
        $envPrefix = $this->config()->get('service.env_prefix');
        if (getenv($envPrefix . 'PROJECT')
            && basename(getenv('SHELL')) === 'dash'
            && !$this->isTerminal(STDIN)) {
            return true;
        }

        return false;
    }

    /**
     * Detects if running within a CI or local container system.
     *
     * @return bool
     */
    private function isCI()
    {
        return getenv('CI') !== false // GitHub Actions, Travis CI, CircleCI, Cirrus CI, GitLab CI, AppVeyor, CodeShip, dsari
            || getenv('BUILD_NUMBER') !== false // Jenkins, TeamCity
            || getenv('RUN_ID') !== false // TaskCluster, dsari
            || getenv('LANDO_INFO') !== false // Lando (https://docs.lando.dev/guides/lando-info.html)
            || getenv('IS_DDEV_PROJECT') === 'true' // DDEV (https://ddev.readthedocs.io/en/latest/users/extend/custom-commands/#environment-variables-provided)
            || $this->detectRunningInHook(); // PSH
    }

    /**
     * Detects if the CLI is running wrapped inside the go wrapper.
     *
     * @return bool
     */
    protected function isWrapped()
    {
        return $this->config()->isWrapped();
    }

    /**
     * Select the project for the user, based on input or the environment.
     *
     * @param string $projectId
     * @param string $host
     * @param bool   $detectCurrent
     *
     * @return Project
     */
    protected function selectProject($projectId = null, $host = null, $detectCurrent = true)
    {
        if (!empty($projectId)) {
            $this->project = $this->api()->getProject($projectId, $host);
            if (!$this->project) {
                throw new ConsoleInvalidArgumentException($this->getProjectNotFoundMessage($projectId));
            }

            return $this->project;
        }

        $this->project = $detectCurrent ? $this->getCurrentProject() : false;
        if (!$this->project && isset($this->input) && $this->input->isInteractive()) {
            $myProjects = $this->api()->getMyProjects();
            if (count($myProjects) > 0) {
                $this->debug('No project specified: offering a choice...');
                $projectId = $this->offerProjectChoice($myProjects);

                return $this->selectProject($projectId);
            }
        }
        if (!$this->project) {
            if ($detectCurrent) {
                throw new RootNotFoundException(
                    "Could not determine the current project."
                    . "\n\nSpecify it using --project, or go to a project directory."
                );
            } else {
                throw new ConsoleInvalidArgumentException('You must specify a project.');
            }
        }

        return $this->project;
    }

    /**
     * Format an error message about a not-found project.
     *
     * @param string $projectId
     *
     * @return string
     */
    private function getProjectNotFoundMessage($projectId)
    {
        $message = 'Specified project not found: ' . $projectId;
        if ($projectInfos = $this->api()->getMyProjects()) {
            $message .= "\n\nYour projects are:";
            $limit = 8;
            foreach (array_slice($projectInfos, 0, $limit) as $info) {
                $message .= "\n    " . $info->id;
                if ($info->title !== '') {
                    $message .= ' - ' . $info->title;
                }
            }
            if (count($projectInfos) > $limit) {
                $message .= "\n    ...";
                $message .= "\n\n    List projects with: " . $this->config()->get('application.executable') . ' projects';
            }
        }

        return $message;
    }

    /**
     * Returns an environment filter to select environments by status.
     *
     * @param string[] $statuses
     *
     * @return callable
     */
    protected function filterEnvsByStatus(array $statuses)
    {
        return function (Environment $e) use ($statuses) {
            return \in_array($e->status, $statuses, true);
        };
    }

    /**
     * Filters environments to those that may be active.
     *
     * @return callable
     */
    protected function filterEnvsMaybeActive()
    {
        return function (Environment $e) {
            return \in_array($e->status, ['active', 'dirty'], true) || count($e->getSshUrls()) > 0;
        };
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
     * @param bool $selectDefaultEnv
     *   Whether to select a default environment.
     * @param bool $detectCurrentEnv
     *   Whether to detect the current environment from Git.
     * @param null|callable $filter
     *   If an interactive choice is given, filter the choice of environments.
     *   This is a callback accepting an Environment and returning a boolean.
     *   Defaults to the $chooseEnvFilter property.
     */
    protected function selectEnvironment($environmentId = null, $required = true, $selectDefaultEnv = false, $detectCurrentEnv = true, $filter = null)
    {
        $envPrefix = $this->config()->get('service.env_prefix');
        if ($environmentId === null && getenv($envPrefix . 'BRANCH')) {
            $environmentId = getenv($envPrefix . 'BRANCH');
            $this->stdErr->writeln(sprintf(
                'Environment ID read from environment variable %s: %s',
                $envPrefix . 'BRANCH',
                $environmentId
            ), OutputInterface::VERBOSITY_VERBOSE);
        }

        if ($environmentId !== null) {
            if ($environmentId === self::DEFAULT_ENVIRONMENT_CODE) {
                $this->stdErr->writeln(sprintf('Selecting default environment (indicated by <info>%s</info>)', $environmentId));
                $environments = $this->api()->getEnvironments($this->project);
                $environment = $this->api()->getDefaultEnvironment($environments, $this->project, true);
                if (!$environment) {
                    throw new \RuntimeException('Default environment not found');
                }
                $this->stdErr->writeln(\sprintf('Selected environment: %s', $this->api()->getEnvironmentLabel($environment)));
                $this->printedSelectedEnvironment = true;
                $this->environment = $environment;
                return;
            }

            $environment = $this->api()->getEnvironment($environmentId, $this->project, null, true);
            if (!$environment) {
                throw new ConsoleInvalidArgumentException('Specified environment not found: ' . $environmentId);
            }

            $this->environment = $environment;
            return;
        }

        if ($detectCurrentEnv && ($environment = $this->getCurrentEnvironment($this->project ?: null))) {
            $this->environment = $environment;
            return;
        }

        if ($selectDefaultEnv) {
            $this->debug('No environment specified or detected: finding a default...');
            $environments = $this->api()->getEnvironments($this->project);
            $environment = $this->api()->getDefaultEnvironment($environments, $this->project);
            if ($environment) {
                $this->stdErr->writeln(\sprintf('Selected default environment: %s', $this->api()->getEnvironmentLabel($environment)));
                $this->printedSelectedEnvironment = true;
                $this->environment = $environment;
                return;
            }
        }

        if ($required && isset($this->input) && $this->input->isInteractive()) {
            $environments = $this->api()->getEnvironments($this->project);
            if ($filter === null && $this->chooseEnvFilter !== null) {
                $filter = $this->chooseEnvFilter;
            }
            if ($filter !== null) {
                $environments = array_filter($environments, $filter);
            }
            if (count($environments) === 1) {
                $only = reset($environments);
                $this->stdErr->writeln(\sprintf('Selected environment: %s (by default)', $this->api()->getEnvironmentLabel($only)));
                $this->printedSelectedEnvironment = true;
                $this->environment = $only;
                return;
            }
            if (count($environments) > 0) {
                $this->debug('No environment specified or detected: offering a choice...');
                $this->environment = $this->offerEnvironmentChoice($environments);
                return;
            }
            throw new ConsoleInvalidArgumentException( 'Could not select an environment automatically.'
                . "\n" . 'Specify one manually using --environment (-e).');
        }

        if ($required && !$this->environment) {
            if ($this->getProjectRoot() || !$detectCurrentEnv) {
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
     * Add the --app and --worker options.
     */
    protected function addRemoteContainerOptions()
    {
        if (!$this->getDefinition()->hasOption('app')) {
            $this->addAppOption();
        }
        if (!$this->getDefinition()->hasOption('worker')) {
            $this->addOption('worker', null, InputOption::VALUE_REQUIRED, 'A worker name');
        }
        $this->addOption('instance', 'I', InputOption::VALUE_REQUIRED, 'An instance ID');
        return $this;
    }

    /**
     * Find what app or worker container the user wants to select.
     *
     * Needs the --app and --worker options, as applicable.
     *
     * @param InputInterface $input
     *   The user input object.
     * @param bool $includeWorkers
     *   Whether to include workers in the selection.
     *
     * @return \Platformsh\Cli\Model\RemoteContainer\RemoteContainerInterface
     *   A class representing a container that allows SSH access.
     */
    protected function selectRemoteContainer(InputInterface $input, $includeWorkers = true)
    {
        if (isset($this->remoteContainer)) {
            return $this->remoteContainer;
        }

        $environment = $this->getSelectedEnvironment();

        try {
            $deployment = $this->api()->getCurrentDeployment(
                $environment,
                $input->hasOption('refresh') && $input->getOption('refresh')
            );
        } catch (EnvironmentStateException $e) {
            if ($environment->isActive() && $e->getMessage() === 'Current deployment not found') {
                $appName = $input->hasOption('app') ? $input->getOption('app') : '';

                return $this->remoteContainer = new RemoteContainer\BrokenEnv($environment, $appName);
            }
            throw $e;
        }

        // Read the --app and --worker options, if the latter is specified then it will be used.
        $appOption = $input->hasOption('app') ? $input->getOption('app') : null;
        $workerOption = $includeWorkers && $input->hasOption('worker') ? $input->getOption('worker') : null;

        if ($appOption !== null) {
            try {
                $webApp = $deployment->getWebApp($appOption);
            } catch (\InvalidArgumentException $e) {
                throw new ConsoleInvalidArgumentException('Application not found: ' . $appOption);
            }

            // No worker option specified so select app directly.
            if ($workerOption === null) {
                $this->stdErr->writeln(sprintf('Selected app: <info>%s</info>', $webApp->name), OutputInterface::VERBOSITY_VERBOSE);

                return $this->remoteContainer = new RemoteContainer\App($webApp, $environment);
            }

            unset($webApp); // object is no longer required.
        }

        if ($workerOption !== null) {
            // Check for a conflict with the --app option.
            if ($appOption !== null
                && strpos($workerOption, '--') !== false
                && stripos($workerOption, $appOption . '--') !== 0) {
                throw new ConsoleInvalidArgumentException(sprintf(
                    'App name "%s" conflicts with worker name "%s"',
                    $appOption,
                    $workerOption
                ));
            }

            // If we have the app name, load the worker directly.
            if (strpos($workerOption, '--') !== false || $appOption !== null) {
                $qualifiedWorkerName = strpos($workerOption, '--') !== false
                    ? $workerOption
                    : $appOption . '--' . $workerOption;
                try {
                    $worker = $deployment->getWorker($qualifiedWorkerName);
                } catch (\InvalidArgumentException $e) {
                    throw new ConsoleInvalidArgumentException('Worker not found: ' . $workerOption . ' (in app: ' . $appOption . ')');
                }
                $this->stdErr->writeln(sprintf('Selected worker: <info>%s</info>', $worker->name), OutputInterface::VERBOSITY_VERBOSE);

                return $this->remoteContainer = new RemoteContainer\Worker($worker, $environment);
            }

            // If we don't have the app name, find all the possible matching
            // workers, and ask the user to choose.
            $suffix = '--' . $workerOption;
            $suffixLength = strlen($suffix);
            $workerNames = [];
            foreach ($deployment->workers as $worker) {
                if (substr($worker->name, -$suffixLength) === $suffix) {
                    $workerNames[] = $worker->name;
                }
            }
            if (count($workerNames) === 0) {
                throw new ConsoleInvalidArgumentException('Worker not found: ' . $workerOption);
            }
            if (count($workerNames) === 1) {
                $workerName = reset($workerNames);
                $this->stdErr->writeln(sprintf('Selected worker: <info>%s</info>', $workerName), OutputInterface::VERBOSITY_VERBOSE);

                return $this->remoteContainer = new RemoteContainer\Worker($deployment->getWorker($workerName), $environment);
            }
            if (!$input->isInteractive()) {
                throw new ConsoleInvalidArgumentException(sprintf(
                    'Ambiguous worker name: %s (matches: %s)',
                    $workerOption,
                    implode(', ', $workerNames)
                ));
            }
            /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');
            $workerName = $questionHelper->choose(
                $workerNames,
                'Enter a number to choose a worker:'
            );
            $this->stdErr->writeln(sprintf('Selected worker: <info>%s</info>', $workerName), OutputInterface::VERBOSITY_VERBOSE);

            return $this->remoteContainer = new RemoteContainer\Worker($deployment->getWorker($workerName), $environment);
        }

        // Prompt the user to choose between the app(s) or worker(s) that have
        // been found.
        $appNames = $appOption !== null
            ? [$appOption]
            : array_map(function (WebApp $app) { return $app->name; }, $deployment->webapps);
        $choices = array_combine($appNames, $appNames);
        $choicesIncludeWorkers = false;
        if ($includeWorkers) {
            $servicesWithSsh = [];
            foreach ($environment->getSshUrls() as $key => $sshUrl) {
                $parts = explode(':', $key, 2);
                $servicesWithSsh[$parts[0]] = $sshUrl;
            }
            foreach ($deployment->workers as $worker) {
                if (!isset($servicesWithSsh[$worker->name])) {
                    // Only include workers in the interactive selection if they
                    // have SSH endpoints. Some Dedicated environments do not have
                    // separate SSH endpoints for workers.
                    continue;
                }
                list($appPart, ) = explode('--', $worker->name, 2);
                if (in_array($appPart, $appNames, true)) {
                    $choices[$worker->name] = $worker->name;
                    $choicesIncludeWorkers = true;
                }
            }
        }
        if (count($choices) === 0) {
            throw new \RuntimeException('Failed to find apps or workers for environment: ' . $environment->id);
        }
        if (count($appNames) === 1) {
            $choice = reset($appNames);
        } elseif ($input->isInteractive()) {
            /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');
            if ($choicesIncludeWorkers) {
                $text = sprintf('Enter a number to choose an app or %s worker:',
                    count($choices) === 2 ? 'its' : 'a'
                );
            } else {
                $text = 'Enter a number to choose an app:';
            }
            ksort($choices, SORT_NATURAL);
            $choice = $questionHelper->choose($choices, $text);
        } else {
            throw new ConsoleInvalidArgumentException(
                $includeWorkers
                    ? 'Specifying the --app or --worker is required in non-interactive mode'
                    : 'Specifying the --app is required in non-interactive mode'
            );
        }

        // Match the choice to a worker or app destination.
        if (strpos($choice, '--') !== false) {
            $this->stdErr->writeln(sprintf('Selected worker: <info>%s</info>', $choice), OutputInterface::VERBOSITY_VERBOSE);
            return $this->remoteContainer = new RemoteContainer\Worker($deployment->getWorker($choice), $environment);
        }

        $this->stdErr->writeln(sprintf('Selected app: <info>%s</info>', $choice), OutputInterface::VERBOSITY_VERBOSE);

        return $this->remoteContainer = new RemoteContainer\App($deployment->getWebApp($choice), $environment);
    }

    /**
     * Find the name of the app the user wants to use.
     *
     * @param InputInterface $input
     *   The user input object.
     *
     * @return string|null
     *   The application name, or null if it could not be found.
     */
    protected function selectApp(InputInterface $input)
    {
        $appName = $input->getOption('app');
        if ($appName) {
            return $appName;
        }

        return $this->selectRemoteContainer($input, false)->getName();
    }

    /**
     * Offer the user an interactive choice of projects.
     *
     * @param BasicProjectInfo[] $projectInfos
     *
     * @return string
     *   The chosen project ID.
     */
    private function offerProjectChoice(array $projectInfos)
    {
        if (!isset($this->input) || !isset($this->output) || !$this->input->isInteractive()) {
            throw new \BadMethodCallException('Not interactive: a project choice cannot be offered.');
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        if (count($projectInfos) >= 25 || count($projectInfos) > (new Terminal())->getHeight() - 3) {
            $autocomplete = [];
            foreach ($projectInfos as $info) {
                if ($info->title) {
                    $autocomplete[$info->id] = $info->id . ' - <question>' . $info->title . '</question>';
                } else {
                    $autocomplete[$info->id] = $info->id;
                }
            }
            asort($autocomplete, SORT_NATURAL | SORT_FLAG_CASE);
            return $questionHelper->askInput($this->enterProjectText, null, array_values($autocomplete), function ($value) use ($autocomplete) {
                list($id, ) = explode(' - ', $value);
                if (empty(trim($id))) {
                    throw new ConsoleInvalidArgumentException('A project ID is required');
                }
                if (!isset($autocomplete[$id]) && !$this->api()->getProject($id)) {
                    throw new ConsoleInvalidArgumentException('Project not found: ' . $id);
                }
                return $id;
            });
        }

        $projectList = [];
        foreach ($projectInfos as $info) {
            $projectList[$info->id] = $this->api()->getProjectLabel($info, false);
        }
        asort($projectList, SORT_NATURAL | SORT_FLAG_CASE);

        return $questionHelper->choose($projectList, $this->chooseProjectText, null, false);
    }

    /**
     * Offers a choice of environments.
     *
     * @param Environment[] $environments
     *
     * @return Environment
     */
    final protected function offerEnvironmentChoice(array $environments)
    {
        if (!isset($this->input) || !isset($this->output) || !$this->input->isInteractive()) {
            throw new \BadMethodCallException('Not interactive: an environment choice cannot be offered.');
        }

        $defaultEnvironment = $this->api()->getDefaultEnvironment($environments, $this->project);
        $defaultEnvironmentId = $defaultEnvironment ? $defaultEnvironment->id : null;

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        if (count($environments) > (new Terminal())->getHeight() / 2) {
            $ids = array_keys($environments);
            sort($ids, SORT_NATURAL | SORT_FLAG_CASE);

            $id = $questionHelper->askInput($this->enterEnvText, $defaultEnvironmentId, array_keys($environments), function ($value) use ($environments) {
                if (!isset($environments[$value])) {
                    throw new \RuntimeException('Environment not found: ' . $value);
                }

                return $value;
            });
        } else {
            $environmentList = [];
            foreach ($environments as $environment) {
                $environmentList[$environment->id] = $this->api()->getEnvironmentLabel($environment, false);
            }
            asort($environmentList, SORT_NATURAL | SORT_FLAG_CASE);

            $text = $this->chooseEnvText;
            if ($defaultEnvironmentId) {
                $text .= "\n" . 'Default: <question>' . $defaultEnvironmentId . '</question>';
            }

            $id = $questionHelper->choose($environmentList, $text, $defaultEnvironmentId, false);
        }

        return $environments[$id];
    }

    /**
     * @param InputInterface $input
     * @param bool           $envNotRequired
     * @param bool           $selectDefaultEnv
     * @param bool           $detectCurrent Whether to detect the project/environment from the current working directory.
     */
    final protected function validateInput(InputInterface $input, $envNotRequired = false, $selectDefaultEnv = false, $detectCurrent = true)
    {
        $projectId = $input->hasOption('project') ? $input->getOption('project') : null;
        $projectHost = $input->hasOption('host') ? $input->getOption('host') : null;
        $environmentId = null;

        // Warn about using the deprecated --host option.
        $this->warnAboutDeprecatedOptions(['host']);

        // Identify the project.
        if ($projectId !== null) {
            /** @var \Platformsh\Cli\Service\Identifier $identifier */
            $identifier = $this->getService('identifier');
            $result = $identifier->identify($projectId);
            $projectId = $result['projectId'];
            $projectHost = $projectHost ?: $result['host'];
            $environmentId = $result['environmentId'];
        }

        // Load the project ID from an environment variable, if available.
        $envPrefix = $this->config()->get('service.env_prefix');
        if ($projectId === null && getenv($envPrefix . 'PROJECT')) {
            $projectId = getenv($envPrefix . 'PROJECT');
            $this->stdErr->writeln(sprintf(
                'Project ID read from environment variable %s: %s',
                $envPrefix . 'PROJECT',
                $projectId
            ), OutputInterface::VERBOSITY_VERBOSE);
        }

        // Set the --app option.
        if ($input->hasOption('app') && !$input->getOption('app') && !$this->getDefinition()->getOption('app')->isArray()) {
            // An app ID might be provided from the parsed project URL.
            if (isset($result['appId'])) {
                $input->setOption('app', $result['appId']);
                $this->debug(sprintf(
                    'App name identified as: %s',
                    $input->getOption('app')
                ));
            }
            // Or from an environment variable.
            elseif (getenv($envPrefix . 'APPLICATION_NAME')) {
                $input->setOption('app', getenv($envPrefix . 'APPLICATION_NAME'));
                $this->stdErr->writeln(sprintf(
                    'App name read from environment variable %s: %s',
                    $envPrefix . 'APPLICATION_NAME',
                    $input->getOption('app')
                ), OutputInterface::VERBOSITY_VERBOSE);
            }
        }

        // Select the project.
        $project = $this->selectProject($projectId, $projectHost, $detectCurrent);
        if ($this->stdErr->isVerbose()) {
            $this->stdErr->writeln('Selected project: ' . $this->api()->getProjectLabel($project));
            $this->printedSelectedProject = true;
        }

        // Select the environment.
        $envOptionName = 'environment';
        $this->printedSelectedEnvironment = false;
        if ($input->hasArgument($this->envArgName)
            && $input->getArgument($this->envArgName) !== null
            && $input->getArgument($this->envArgName) !== []) {
            if ($input->hasOption($envOptionName) && $input->getOption($envOptionName) !== null) {
                throw new ConsoleInvalidArgumentException(
                    sprintf(
                        'You cannot use both the <%s> argument and the --%s option',
                        $this->envArgName,
                        $envOptionName
                    )
                );
            }
            $argument = $input->getArgument($this->envArgName);
            if (is_array($argument)) {
                $argument = ArrayArgument::split($argument);
                if (count($argument) === 1) {
                    $argument = $argument[0];
                }
            }
            if (!is_array($argument)) {
                $this->debug('Selecting environment based on input argument');
                $this->selectEnvironment($argument, true, $selectDefaultEnv, $detectCurrent);
            }
        } elseif ($input->hasOption($envOptionName)) {
            if ($input->getOption($envOptionName) !== null) {
                $environmentId = $input->getOption($envOptionName);
            }
            $this->selectEnvironment($environmentId, !$envNotRequired, $selectDefaultEnv, $detectCurrent);
        }

        if ($this->stdErr->isVerbose()) {
            $this->ensurePrintSelectedEnvironment();
        }
    }

    /**
     * Prints the selected project, if it has not already been printed.
     *
     * @param bool $blankLine Append an extra newline after the message, if any is printed.
     */
    protected function ensurePrintSelectedProject($blankLine = false) {
        if (!$this->printedSelectedProject && $this->project) {
            $this->stdErr->writeln('Selected project: ' . $this->api()->getProjectLabel($this->project));
            $this->printedSelectedProject = true;
            if ($blankLine) {
                $this->stdErr->writeln('');
            }
        }
    }

    /**
     * Prints the selected environment, if it has not already been printed.
     *
     * Also prints the selected project if necessary.
     *
     * @param bool $blankLine Append an extra newline after the message, if any is printed.
     */
    protected function ensurePrintSelectedEnvironment($blankLine = false) {
        if (!$this->printedSelectedEnvironment) {
            if (!$this->environment) {
                $this->ensurePrintSelectedProject($blankLine);
                return;
            }
            $this->ensurePrintSelectedProject();
            $this->stdErr->writeln('Selected environment: ' . $this->api()->getEnvironmentLabel($this->environment));
            $this->printedSelectedEnvironment = true;
            if ($blankLine) {
                $this->stdErr->writeln('');
            }
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
     * @param string $name
     *   The name of the other command.
     * @param array $arguments
     *   Arguments for the other command.
     *   Unambiguous options that both commands have in common will be passed
     *   on automatically.
     * @param OutputInterface $output
     *   The output for the other command. Defaults to the current output.
     *
     * @return int
     */
    protected function runOtherCommand($name, array $arguments = [], OutputInterface $output = null)
    {
        /** @var \Platformsh\Cli\Application $application */
        $application = $this->getApplication();
        /** @var Command $command */
        $command = $application->find($name);

        if (isset($this->input)) {
            $this->forwardStandardOptions($arguments, $this->input, $command->getDefinition());
        }

        $cmdInput = new ArrayInput(['command' => $name] + $arguments);
        if (!empty($arguments['--yes']) || !empty($arguments['--no'])) {
            $cmdInput->setInteractive(false);
        } elseif (isset($this->input)) {
            $cmdInput->setInteractive($this->input->isInteractive());
        }

        if ($this->stdErr->isVeryVerbose()) {
            $this->stdErr->writeln(
                '<options=reverse>#</> Running subcommand: <info>' . $cmdInput->__toString() . '</info>'
            );
        }

        // Give the other command an entirely new service container, because the
        // "input" and "output" parameters, and all their dependents, need to
        // change.
        $container = self::$container;
        self::$container = null;
        $application->setCurrentCommand($command);

        // Use a try/finally pattern to ensure that state is restored, even if
        // an exception is thrown in $command->run() and caught by the caller.
        try {
            $result = $command->run($cmdInput, $output ?: $this->output);
        } finally {
            $application->setCurrentCommand($this);
            // Restore the old service container.
            self::$container = $container;
        }

        return $result;
    }

    /**
     * Forwards standard (unambiguous) arguments that a source and target command have in common.
     *
     * @param array &$args
     * @param InputInterface $input
     * @param InputDefinition $targetDef
     */
    private function forwardStandardOptions(array &$args, InputInterface $input, InputDefinition $targetDef)
    {
        $stdOptions = [
            'no',
            'no-interaction',
            'yes',

            'no-wait',
            'wait',

            'org',
            'host',
            'project',
            'environment',
            'app',
            'worker',
            'instance',
        ];
        foreach ($stdOptions as $name) {
            if (!\array_key_exists('--' . $name, $args) && $targetDef->hasOption($name) && $input->hasOption($name)) {
                $value = $input->getOption($name);
                if ($value !== null && $value !== false) {
                    $args['--' . $name] = $value;
                }
            }
        }
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
        $this->labeledMessage('DEBUG', $message, OutputInterface::VERBOSITY_DEBUG);
    }

    /**
     * Print a warning about deprecated option(s).
     *
     * @param string[]    $options  A list of option names (without "--").
     * @param string|null $template The warning message template. "%s" is
     *                              replaced by the option name.
     */
    protected function warnAboutDeprecatedOptions(array $options, $template = null)
    {
        if (!isset($this->input)) {
            return;
        }
        if ($template === null) {
            $template = 'The option --%s is deprecated and no longer used. It will be removed in a future version.';
        }
        foreach ($options as $option) {
            if ($this->input->hasOption($option) && $this->input->getOption($option)) {
                $this->labeledMessage(
                    'DEPRECATED',
                    sprintf($template, $option)
                );
            }
        }
    }

    /**
     * Print a message with a label.
     *
     * @param string $label
     * @param string $message
     * @param int    $options
     */
    private function labeledMessage($label, $message, $options = 0)
    {
        if (isset($this->stdErr)) {
            $this->stdErr->writeln('<options=reverse>' . strtoupper($label) . '</> ' . $message, $options);
        }
    }

    /**
     * Get a service object.
     *
     * Services are configured in services.yml, and loaded via the Symfony
     * Dependency Injection component.
     *
     * When using this method, always store the result in a temporary variable,
     * so that the service's type can be hinted in a variable docblock (allowing
     * IDEs and other analysers to check subsequent code). For example:
     * <code>
     *   /** @var \Platformsh\Cli\Service\Filesystem $fs *\/
     *   $fs = $this->getService('fs');
     * </code>
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
            $definition = clone $this->getDefinition();
            $definition->setOptions(array_filter($definition->getOptions(), function (InputOption $opt) {
                return !$opt instanceof HiddenInputOption;
            }));

            $this->synopsis[$key] = trim(sprintf(
                '%s %s %s',
                $this->config()->get('application.executable'),
                $this->getPreferredName(),
                $definition->getSynopsis($short)
            ));
        }

        return $this->synopsis[$key];
    }

    /**
     * Returns the preferred command name for use in help.
     *
     * @return string
     */
    public function getPreferredName()
    {
        if ($visibleAliases = $this->getVisibleAliases()) {
            return reset($visibleAliases);
        }
        return $this->getName();
    }

    /**
     * @param resource|int $descriptor
     *
     * @return bool
     */
    protected function isTerminal($descriptor)
    {
        return !function_exists('posix_isatty') || posix_isatty($descriptor);
    }

    /**
     * {@inheritdoc}
     */
    public function isEnabled()
    {
        return $this->config()->isCommandEnabled($this->getName());
    }

    /**
     * Get help on how to use API tokens non-interactively.
     *
     * @param string $tag
     *
     * @return string
     */
    protected function getNonInteractiveAuthHelp($tag = 'info')
    {
        $prefix = $this->config()->get('application.env_prefix');

        return "To authenticate non-interactively, configure an API token using the <$tag>${prefix}TOKEN</$tag> environment variable.";
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription() {
        $description = parent::getDescription();

        if ($this->stability !== self::STABILITY_STABLE) {
            $tag = $this->stability === self::STABILITY_DEPRECATED ? '<fg=black;bg=yellow>' : '<fg=white;bg=red>';
            $prefix = $tag . strtoupper($this->stability) . '</> ';
            $description = $prefix . $description;
        }

        return $description;
    }

    /**
     * @param InputInterface $input
     * @param bool $allowLocal
     * @param RemoteContainer\RemoteContainerInterface|null $remoteContainer
     * @param bool $includeWorkers
     *
     * @return HostInterface
     */
    public function selectHost(InputInterface $input, $allowLocal = true, RemoteContainer\RemoteContainerInterface $remoteContainer = null, $includeWorkers = true)
    {
        /** @var Shell $shell */
        $shell = $this->getService('shell');

        if ($allowLocal && !LocalHost::conflictsWithCommandLineOptions($input, $this->config()->get('service.env_prefix'))) {
            $this->debug('Selected host: localhost');

            return new LocalHost($shell);
        }

        if ($remoteContainer === null) {
            if (!$this->hasSelectedEnvironment()) {
                $this->chooseEnvFilter = $this->filterEnvsMaybeActive();
                $this->validateInput($input);
            }
            $remoteContainer = $this->selectRemoteContainer($input, $includeWorkers);
        }

        $instanceId = $input->hasOption('instance') ? $input->getOption('instance') : null;
        if ($input->hasOption('instance') && $instanceId !== null) {
            $instances = $this->getSelectedEnvironment()->getSshInstanceURLs($remoteContainer->getName());
            if ((!empty($instances) || $instanceId !== '0') && !isset($instances[$instanceId])) {
                throw new ConsoleInvalidArgumentException("Instance not found: $instanceId. Available instances: " . implode(', ', array_keys($instances)));
            }
        }

        /** @var Ssh $ssh */
        $ssh = $this->getService('ssh');
        /** @var \Platformsh\Cli\Service\SshDiagnostics $sshDiagnostics */
        $sshDiagnostics = $this->getService('ssh_diagnostics');

        $sshUrl = $remoteContainer->getSshUrl($instanceId);
        $this->debug('Selected host: ' . $sshUrl);
        return new RemoteHost($sshUrl, $this->getSelectedEnvironment(), $ssh, $shell, $sshDiagnostics);
    }

    /**
     * Finalizes login: refreshes SSH certificate, prints account information.
     */
    protected function finalizeLogin()
    {
        // Reset the API client so that it will use the new tokens.
        $this->api()->getClient(false, true);
        $this->stdErr->writeln('You are logged in.');

        /** @var \Platformsh\Cli\Service\SshConfig $sshConfig */
        $sshConfig = $this->getService('ssh_config');

        // Configure SSH host keys.
        $sshConfig->configureHostKeys();

        // Generate a new certificate from the certifier API.
        /** @var \Platformsh\Cli\SshCert\Certifier $certifier */
        $certifier = $this->getService('certifier');
        if ($certifier->isAutoLoadEnabled() && $sshConfig->checkRequiredVersion()) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('Generating SSH certificate...');
            try {
                $certifier->generateCertificate(null);
                $this->stdErr->writeln('A new SSH certificate has been generated.');
                $this->stdErr->writeln('It will be automatically refreshed when necessary.');
            } catch (\Exception $e) {
                $this->stdErr->writeln('Failed to generate SSH certificate: <error>' . $e->getMessage() . '</error>');
            }
        }

        // Write session-based SSH configuration.
        if ($sshConfig->configureSessionSsh()) {
            /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');
            $sshConfig->addUserSshConfig($questionHelper);
        }

        // Show user account info.
        $account = $this->api()->getMyAccount(true);
        $this->stdErr->writeln(sprintf(
            "\nUsername: <info>%s</info>\nEmail address: <info>%s</info>",
            $account['username'],
            $account['email']
        ));
    }

    /**
     * Shows information about the currently logged in user and their session, if applicable.
     *
     * @param bool $logout  Whether this should avoid re-authentication (if an API token is set).
     * @param bool $newline Whether to prepend a newline if there is output.
     */
    protected function showSessionInfo($logout = false, $newline = true)
    {
        $api = $this->api();
        $config = $this->config();
        $sessionId = $config->getSessionId();
        if ($sessionId !== 'default' || count($api->listSessionIds()) > 1) {
            if ($newline) {
                $this->stdErr->writeln('');
                $newline = false;
            }
            $this->stdErr->writeln(sprintf('The current session ID is: <info>%s</info>', $sessionId));
            if (!$config->isSessionIdFromEnv()) {
                $this->stdErr->writeln(sprintf('Change this using: <info>%s session:switch</info>', $config->get('application.executable')));
            }
        }
        if (!$logout && $api->isLoggedIn()) {
            if ($newline) {
                $this->stdErr->writeln('');
            }
            $account = $api->getMyAccount();
            $this->stdErr->writeln(\sprintf(
                'You are logged in as <info>%s</info> (<info>%s</info>)',
                $account['username'],
                $account['email']
            ));
        }
    }

    /**
     * Adds the --org (-o) organization name option.
     *
     * @param bool $includeProjectOption
     *   Adds a --project option which means the organization may be
     *   auto-selected based on the current or specified project.
     *
     * @return self
     */
    protected function addOrganizationOptions($includeProjectOption = false)
    {
        if ($this->config()->getWithDefault('api.organizations', false)) {
            $this->addOption('org', 'o', InputOption::VALUE_REQUIRED, 'The organization name (or ID)');
            if ($includeProjectOption && !$this->getDefinition()->hasOption('project')) {
                $this->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'The project ID or URL, which auto-selects the organization if --org is not used');
            }
        }
        return $this;
    }

    /**
     * Returns the selected organization according to the --org option.
     *
     * @param InputInterface $input
     * @param string $filterByLink
     *   If no organization is specified, this filters the list of the organizations presented by the name of a HAL
     *   link. For example, 'create-subscription' will list organizations under which the user has the permission to
     *   create a subscription.
     * @param string $filterByCapability
     *  If no organization is specified, this filters the list of the organizations presented to those with the given
     *  capability.
     * @param bool $skipCache
     *
     * @return Organization
     * @throws NoOrganizationsException if the user does not have any organizations matching the filter
     *
     * @throws \InvalidArgumentException if no organization is specified
     * @see CommandBase::addOrganizationOptions()
     *
     */
    protected function validateOrganizationInput(InputInterface $input, $filterByLink = '', $filterByCapability = '', $skipCache = false)
    {
        if (!$this->config()->getWithDefault('api.organizations', false)) {
            throw new \BadMethodCallException('Organizations are not enabled');
        }

        if (($input->hasOption('project') && $input->getOption('project')) || $this->getCurrentProject(true)) {
            $this->validateInput($input);
        }

        if ($identifier = $input->getOption('org')) {
            // Organization names have to be lower case, while organization IDs are the uppercase ULID format.
            // So it's easy to distinguish one from the other.
            /** @link https://github.com/ulid/spec */
            if (\preg_match('#^[0-9A-HJKMNP-TV-Z]{26}$#', $identifier) === 1) {
                $this->debug('Detected organization ID format (ULID): ' . $identifier);
                $organization = $this->api()->getOrganizationById($identifier, $skipCache);
            } else {
                $organization = $this->api()->getOrganizationByName($identifier, $skipCache);
            }
            if (!$organization) {
                throw new ConsoleInvalidArgumentException('Organization not found: ' . $identifier);
            }

            // Check for a conflict between the --org and the --project options.
            if (($input->hasOption('project') && $input->getOption('project'))
                && $this->hasSelectedProject() && ($project = $this->getSelectedProject())
                && $project->getProperty('organization', true, false) !== $organization->id) {
                throw new ConsoleInvalidArgumentException("The project $project->id is not part of the organization $organization->id");
            }

            return $organization;
        }

        if ($this->hasSelectedProject()) {
            $project = $this->getSelectedProject();
            $this->ensurePrintSelectedProject();
            $organization = $this->api()->getOrganizationById($project->getProperty('organization'), $skipCache);
            if ($organization) {
                $this->stdErr->writeln(\sprintf('Project organization: %s', $this->api()->getOrganizationLabel($organization)));
                return $organization;
            }
        } elseif (($currentProject = $this->getCurrentProject(true)) && $currentProject->hasProperty('organization')) {
            $organizationId = $currentProject->getProperty('organization');
            try {
                $organization = $this->api()->getOrganizationById($organizationId, $skipCache);
            } catch (BadResponseException $e) {
                $this->debug('Error when fetching project organization: ' . $e->getMessage());
                $organization = false;
            }
            if ($organization) {
                if ($filterByLink === '' || $organization->hasLink($filterByLink)) {
                    if ($this->stdErr->isVerbose()) {
                        $this->ensurePrintSelectedProject();
                        $this->stdErr->writeln(\sprintf('Project organization: %s', $this->api()->getOrganizationLabel($organization)));
                    }
                    return $organization;
                } elseif ($this->stdErr->isVerbose()) {
                    $this->stdErr->writeln(sprintf(
                        'Not auto-selecting project organization %s (it does not have the link <comment>%s</comment>)',
                        $this->api()->getOrganizationLabel($organization, 'comment'),
                        $filterByLink
                    ));
                }
            }
        }

        $userId = $this->api()->getMyUserId();
        $organizations = $this->api()->getClient()->listOrganizationsWithMember($userId);

        if (!$input->isInteractive()) {
            throw new ConsoleInvalidArgumentException('An organization name or ID (--org) is required.');
        }
        if (!$organizations) {
            throw new NoOrganizationsException('No organizations found.', 0);
        }

        $this->api()->sortResources($organizations, 'name');
        $options = [];
        $byId = [];
        $owned = [];
        foreach ($organizations as $organization) {
            if ($filterByLink !== '' && !$organization->hasLink($filterByLink)) {
                continue;
            }
            if ($filterByCapability !== '' && !in_array($filterByCapability, $organization->capabilities, true)) {
                continue;
            }
            $options[$organization->id] = $this->api()->getOrganizationLabel($organization, false);
            $byId[$organization->id] = $organization;
            if ($organization->owner_id === $userId) {
                $owned[$organization->id] = $organization;
            }
        }
        if (empty($options)) {
            $message = 'No organizations found.';
            $filters = [];
            if ($filterByLink !== '') {
                $filters[] = sprintf('access to the link "%s"', $filterByLink);
            }
            if ($filterByCapability !== '') {
                $filters[] = sprintf('capability "%s"', $filterByCapability);
            }
            if ($filters) {
                $message = sprintf('No organizations found (filtered by %s).', implode(' and ', $filters));
            }
            throw new NoOrganizationsException($message, count($organizations));
        }
        if (count($byId) === 1) {
            /** @var Organization $organization */
            $organization = reset($byId);
            $this->stdErr->writeln(\sprintf('Selected organization: %s (by default)', $this->api()->getOrganizationLabel($organization)));
            return $organization;
        }
        $default = null;
        if (count($owned) === 1) {
            $default = key($owned);

            // Move the default to the top of the list and label it.
            $options = [$default => $options[$default] . ' <info>(default)</info>'] + $options;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $id = $questionHelper->choose($options, 'Enter a number to choose an organization (<fg=cyan>-o</>):', $default);
        return $byId[$id];
    }

    /**
     * Adds a --resources-init option to commands that support it.
     *
     * The option will only be added if the api.sizing feature is enabled.
     *
     * @param string[] $values
     *   The possible values, with the default as the first element.
     *
     * @return self
     *
     * @see CommandBase::validateResourcesInitInput()
     */
    protected function addResourcesInitOption($values, $description = '')
    {
        if (!$this->config()->get('api.sizing')) {
            return $this;
        }
        $this->validResourcesInitValues = $values;
        if ($description === '') {
            $description = 'Set the resources to use for new services';
            $description .= ': ' . StringUtil::formatItemList($values);
            $default = array_shift($values);
            $description .= ".\n" . sprintf('If not set, "%s" will be used.', $default);
        }
        $this->addOption('resources-init', null, InputOption::VALUE_REQUIRED, $description);

        return $this;
    }

    /**
     * Validates and returns the --resources-init input, if any.
     *
     * @param InputInterface $input
     * @param Project $project
     *
     * @return string|false|null
     *   The input value, or false if there was a validation error, or null if
     *   nothing was specified or the input option didn't exist.
     *
     * @see CommandBase::addResourcesInitOption()
     */
    protected function validateResourcesInitInput(InputInterface $input, Project $project)
    {
        $resourcesInit = $input->hasOption('resources-init') ? $input->getOption('resources-init') : null;
        if ($resourcesInit !== null) {
            if (!\in_array($resourcesInit, $this->validResourcesInitValues, true)) {
                $this->stdErr->writeln('The value for <error>--resources-init</error> must be one of: ' . \implode(', ', $this->validResourcesInitValues));
                return false;
            }
            if (!$this->api()->supportsSizingApi($project)) {
                $this->stdErr->writeln('The <comment>--resources-init</comment> option cannot be used as the project does not support flexible resources.');
                return false;
            }
        }
        return $resourcesInit;
    }

    /**
     * Warn the user if a project is suspended.
     *
     * @param \Platformsh\Client\Model\Project $project
     */
    protected function warnIfSuspended(Project $project)
    {
        if ($project->isSuspended()) {
            $this->stdErr->writeln('This project is <error>suspended</error>.');
            if ($this->config()->getWithDefault('warnings.project_suspended_payment', true)) {
                $orgId = $project->getProperty('organization', false);
                if ($orgId) {
                    try {
                        $organization = $this->api()->getClient()->getOrganizationById($orgId);
                    } catch (BadResponseException $e) {
                        $organization = false;
                    }
                    if ($organization && $organization->hasLink('payment-source')) {
                        $this->stdErr->writeln(sprintf('To re-activate it, update the payment details for your organization, %s.', $this->api()->getOrganizationLabel($organization, 'comment')));
                    }
                } elseif ($project->owner === $this->api()->getMyUserId()) {
                    $this->stdErr->writeln('To re-activate it, update your payment details.');
                }
            }
        }
    }

    /**
     * Tests if a project's Git host is external (e.g. Bitbucket, GitHub, GitLab, etc.).
     *
     * @param Project $project
     * @return bool
     */
    protected function hasExternalGitHost(Project $project)
    {
        /** @var \Platformsh\Cli\Service\Ssh $ssh */
        $ssh = $this->getService('ssh');

        return $ssh->hostIsInternal($project->getGitUrl()) === false;
    }
}
