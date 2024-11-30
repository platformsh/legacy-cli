<?php

namespace Platformsh\Cli\Command;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\Self\SelfInstallCommand;
use Platformsh\Cli\Console\HiddenInputOption;
use Platformsh\Cli\Event\EnvironmentsChangedEvent;
use Platformsh\Cli\Event\LoginRequiredEvent;
use Platformsh\Cli\Exception\LoginRequiredException;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Cli\Util\StringUtil;
use Platformsh\Client\Model\Project;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

abstract class CommandBase extends Command implements MultiAwareInterface
{
    use HasExamplesTrait;

    const STABILITY_STABLE = 'STABLE';
    const STABILITY_BETA = 'BETA';
    const STABILITY_DEPRECATED = 'DEPRECATED';

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

    protected $hiddenInList = false;
    protected $stability = self::STABILITY_STABLE;
    protected $local = false;
    protected $canBeRunMultipleTimes = true;
    protected $runningViaMulti = false;

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
     * The command synopsis.
     *
     * @var array
     */
    private $synopsis = [];

    /**
     * {@inheritdoc}
     */
    public function isHidden(): bool
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
     * @return \Platformsh\Cli\Selector\Selector
     */
    protected function selector(): Selector
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->getService(Selector::class);
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

            $projectRoot = $this->selector()->getProjectRoot();
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
    public function isLocal(): bool
    {
        return $this->local;
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
        $projectRoot = $this->selector()->getProjectRoot();
        if (!$projectRoot) {
            return;
        }
        // Make sure the local:drush-aliases command is enabled.
        if (!$this->getApplication()->has('local:drush-aliases')) {
            return;
        }
        // Double-check that the passed project is the current one, and that it
        // still exists.
        try {
            $currentProject = $this->selector()->getCurrentProject();
            if (!$currentProject || $currentProject->id != $event->getProject()->id) {
                return;
            }
        } catch (ProjectNotFoundException $e) {
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
    public function getProcessedHelp(): string
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
    public function getSynopsis($short = false): string
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
    public function isEnabled(): bool
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

        return "To authenticate non-interactively, configure an API token using the <$tag>{$prefix}TOKEN</$tag> environment variable.";
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string {
        $description = parent::getDescription();

        if ($this->stability !== self::STABILITY_STABLE) {
            $tag = $this->stability === self::STABILITY_DEPRECATED ? '<fg=black;bg=yellow>' : '<fg=white;bg=red>';
            $prefix = $tag . strtoupper($this->stability) . '</> ';
            $description = $prefix . $description;
        }

        return $description;
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
