<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Console\HiddenInputOption;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Client\Model\Project;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Command\Command;
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
            $loader->load(CLI_ROOT . '/config/services.yaml');
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
