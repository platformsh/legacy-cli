<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Application;
use Platformsh\Cli\Event\EnvironmentsChangedEvent;
use Platformsh\Cli\Exception\LoginRequiredException;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Drush;
use Platformsh\Cli\Service\SelfUpdater;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\State;
use Platformsh\Cli\Service\Url;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class CommandBase extends Command implements MultiAwareInterface
{
    use HasExamplesTrait;

    /** @var bool */
    private static $checkedUpdates;

    /** @var OutputInterface|null */
    protected $stdErr;

    protected $envArgName = 'environment';
    protected $canBeRunMultipleTimes = true;
    protected $runningViaMulti = false;

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
     * The command synopsis.
     *
     * @var array
     */
    private $synopsis = [];

    /**
     * @inheritdoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        // Set up dependencies that are only needed once per command run.
        $this->output = $output;
        $this->input = $input;
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

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
            $this->api = $this->getService(Api::class);
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
        $localProject = $this->getService(LocalProject::class);
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

        // Check if the file is writable.
        if (!is_writable($pharFilename)) {
            return;
        }

        // Check if updates are configured.
        $config = $this->config();
        if (!$config->get('updates.check')) {
            return;
        }

        // Determine an embargo time, after which updates can be checked.
        $timestamp = time();
        $embargoTime = $timestamp - $config->get('updates.check_interval');

        // Stop if updates were last checked after the embargo time.
        /** @var State $state */
        $state = $this->getService(State::class);
        if ($state->get('updates.last_checked') > $embargoTime) {
            return;
        }

        // Stop if the Phar was updated after the embargo time.
        if (filemtime($pharFilename) > $embargoTime) {
            return;
        }

        // Ensure classes are auto-loaded if they may be needed after the
        // update.
        /** @var Shell $shell */
        $shell = $this->getService(Shell::class);
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $currentVersion = $this->config()->get('application.version');

        /** @var SelfUpdater $cliUpdater */
        $cliUpdater = $this->getService(SelfUpdater::class);
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

        // If the update was successful, and it's not a major version change,
        // then prompt the user to continue after updating.
        if ($newVersion !== false) {
            $exitCode = 0;
            list($currentMajorVersion,) = explode('.', $currentVersion, 2);
            list($newMajorVersion,) = explode('.', $newVersion, 2);
            if ($newMajorVersion === $currentMajorVersion
                && isset($this->input)
                && $this->input instanceof ArgvInput
                && is_executable($pharFilename)) {
                $originalCommand = $this->input->__toString();
                $questionText = "\n"
                    . 'Original command: <info>' . $originalCommand . '</info>'
                    . "\n\n" . 'Continue?';
                if ($questionHelper->confirm($questionText)) {
                    $this->stdErr->writeln('');
                    $exitCode = $shell->executeSimple(escapeshellarg($pharFilename) . ' ' . $originalCommand);
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
        $success = false;
        if ($this->output && $this->input && $this->input->isInteractive()) {
            $method = $this->config()->getWithDefault('application.login_method', 'browser');
            if ($method === 'browser') {
                /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
                $questionHelper = $this->getService('question_helper');
                /** @var Url $urlService */
                $urlService = $this->getService(Url::class);
                if ($urlService->canOpenUrls()
                    && $questionHelper->confirm("Authentication is required.\nLog in via a browser?")) {
                    $this->stdErr->writeln('');
                    $exitCode = $this->runOtherCommand('auth:browser-login');
                    $this->stdErr->writeln('');
                    $success = $exitCode === 0;
                }
            } elseif ($method === 'password') {
                $exitCode = $this->runOtherCommand('auth:password-login');
                $this->stdErr->writeln('');
                $success = $exitCode === 0;
            }
        }
        if (!$success) {
            throw new LoginRequiredException();
        }
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
        // Double-check that the passed project is the current one.
        $currentProject = $this->getCurrentProject();
        if (!$currentProject || $currentProject->id != $event->getProject()->id) {
            return;
        }
        // Ignore the project if it doesn't contain a Drupal application.
        if (!Drupal::isDrupal($projectRoot)) {
            return;
        }
        /** @var Drush $drush */
        $drush = $this->getService(Drush::class);
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

        // Give the other command an entirely new service container, because the
        // "input" and "output" parameters, and all their dependents, need to
        // change.
        // @todo upgrade this now the container belongs to the application

        $application->setCurrentCommand($command);
        $result = $command->run($cmdInput, $output ?: $this->output);
        $application->setCurrentCommand($this);

        // Restore the old service container.
        // @todo

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
        $this->labeledMessage('DEBUG', $message, OutputInterface::VERBOSITY_DEBUG);
    }

    /**
     * Print a warning about an deprecated option.
     *
     * @param string[] $options
     */
    protected function warnAboutDeprecatedOptions(array $options)
    {
        if (!isset($this->input)) {
            return;
        }
        foreach ($options as $option) {
            if ($this->input->hasOption($option) && $this->input->getOption($option)) {
                $this->labeledMessage(
                    'DEPRECATED',
                    'The option --' . $option . ' is deprecated and no longer used. It will be removed in a future version.',
                    OutputInterface::VERBOSITY_VERBOSE
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
     * @deprecated Should use DI instead
     *
     * @return object The associated service object.
     */
    protected function getService($name)
    {
        return Application::container()->get($name);
    }

    /**
     * Get the configuration service.
     *
     * @return \Platformsh\Cli\Service\Config
     */
    protected function config()
    {
        return $this->getService(Config::class);
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
     * Get help on how to use API tokens.
     *
     * @param string $tag
     *
     * @return string|null
     */
    protected function getApiTokenHelp($tag = 'info')
    {
        if ($this->config()->has('service.api_token_help_url')) {
            return "To authenticate non-interactively using an API token, see:\n    <$tag>"
                . $this->config()->get('service.api_token_help_url') . "</$tag>";
        }

        return null;
    }
}
