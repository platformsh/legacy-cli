<?php

declare(strict_types=1);

namespace Platformsh\Cli;

use Doctrine\Common\Cache\CacheProvider;
use Platformsh\Cli\Command\HelpCommand;
use Platformsh\Cli\Command\ListCommand;
use Platformsh\Cli\Command\WelcomeCommand;
use Platformsh\Cli\Command\MultiAwareInterface;
use Platformsh\Cli\Console\EventSubscriber;
use Platformsh\Cli\Console\HiddenInputOption;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\LegacyMigration;
use Platformsh\Cli\Service\SelfInstallChecker;
use Platformsh\Cli\Service\SelfUpdateChecker;
use Platformsh\Cli\Util\TimezoneUtil;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Application as ParentApplication;
use Symfony\Component\Console\Command\Command as ConsoleCommand;
use Symfony\Component\Console\Command\CompleteCommand;
use Symfony\Component\Console\Command\DumpCompletionCommand;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\DependencyInjection\AddConsoleCommandPass;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\EventDispatcher\EventDispatcher;

class Application extends ParentApplication
{
    private readonly Config $config;

    private ContainerInterface $container;

    private readonly string $envPrefix;

    private bool $runningViaMulti = false;

    public function __construct(?Config $config = null)
    {
        // Initialize configuration (from config.yaml).
        $this->config = $config ?: new Config();
        $this->envPrefix = $this->config->getStr('application.env_prefix');
        parent::__construct($this->config->getStr('application.name'), $this->config->getVersion());

        // Use the configured timezone, or fall back to the system timezone.
        date_default_timezone_set(
            $this->config->getWithDefault('application.timezone', null)
                ?: TimezoneUtil::getTimezone(),
        );

        // Set the Config service.
        $this->container()->set(Config::class, $this->config);

        // Set up the command loader, which will load commands that are tagged
        // appropriately in the services.yaml container configuration (any
        // services tagged with "console.command").
        /** @var CommandLoaderInterface $loader */
        $loader = $this->container()->get('console.command_loader');
        $this->setCommandLoader($loader);

        // Set "welcome" as the default command.
        $this->setDefaultCommand(WelcomeCommand::getDefaultName());

        // Set up an event subscriber, which will listen for Console events.
        $dispatcher = new EventDispatcher();
        /** @var CacheProvider $cache */
        $cache = $this->container()->get(CacheProvider::class);
        $dispatcher->addSubscriber(new EventSubscriber($cache, $this->config));
        $this->setDispatcher($dispatcher);
    }

    /**
     * {@inheritDoc}
     */
    public function getVersion(): string
    {
        return $this->config->getVersion();
    }

    /**
     * Re-compile the container and other caches.
     */
    public static function warmCaches(): void
    {
        require_once dirname(__DIR__) . '/constants.php';
        $a = new self();
        $a->container(true);
    }

    /**
     * {@inheritdoc}
     *
     * Prevent commands being enabled, according to config.yaml configuration.
     */
    public function add(ConsoleCommand $command): ?ConsoleCommand
    {
        if (!$this->config->isCommandEnabled($command->getName())) {
            $command->setApplication(null);
            return null;
        }

        return parent::add($command);
    }

    /**
     * Returns the Dependency Injection Container for the whole application.
     *
     * @param bool $recompile
     *
     * @return ContainerInterface
     */
    private function container(bool $recompile = false): ContainerInterface
    {
        $cacheFile = __DIR__ . '/../config/cache/container.php';
        $servicesFile = __DIR__ . '/../config/services.yaml';

        if (!isset($this->container)) {
            if (file_exists($cacheFile) && !$recompile) {
                // Load the cached container.
                require_once $cacheFile;
                /** @noinspection PhpUndefinedClassInspection */
                /** @noinspection PhpFieldAssignmentTypeMismatchInspection */
                $this->container = new \ProjectServiceContainer();
            } else {
                // Compile a new container.
                $this->container = new ContainerBuilder();
                try {
                    (new YamlFileLoader($this->container, new FileLocator()))
                        ->load($servicesFile);
                } catch (\Exception $e) {
                    throw new \RuntimeException(sprintf(
                        'Failed to load services.yaml file %s: %s',
                        $servicesFile,
                        $e->getMessage(),
                    ));
                }
                $this->container->addCompilerPass(new AddConsoleCommandPass());
                $this->container->compile();
                $dumper = new PhpDumper($this->container);
                if (!is_dir(dirname($cacheFile))) {
                    mkdir(dirname($cacheFile), 0o755, true);
                }
                file_put_contents($cacheFile, $dumper->dump());
            }
        }

        return $this->container;
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultInputDefinition(): InputDefinition
    {
        return new InputDefinition([
            new InputArgument('command', InputArgument::REQUIRED, 'The command to execute'),
            new InputOption('--help', '-h', InputOption::VALUE_NONE, 'Display this help message'),
            new InputOption('--version', '-V', InputOption::VALUE_NONE, 'Display this application version'),
            new InputOption('--verbose', '-v|vv|vvv', InputOption::VALUE_NONE, 'Increase the verbosity of messages'),
            new InputOption('--quiet', '-q', InputOption::VALUE_NONE, 'Only print necessary output; suppress other messages and errors. This implies --no-interaction. It is ignored in verbose mode.'),
            new InputOption('--yes', '-y', InputOption::VALUE_NONE, 'Answer "yes" to confirmation questions; accept the default value for other questions; disable interaction'),
            new InputOption(
                '--no-interaction',
                null,
                InputOption::VALUE_NONE,
                'Do not ask any interactive questions; accept default values. '
                . sprintf('Equivalent to using the environment variable: <comment>%sNO_INTERACTION=1</comment>', $this->envPrefix),
            ),
            new HiddenInputOption('--ansi', '', InputOption::VALUE_NONE, 'Force ANSI output'),
            new HiddenInputOption('--no-ansi', '', InputOption::VALUE_NONE, 'Disable ANSI output'),
            new HiddenInputOption('--no', '-n', InputOption::VALUE_NONE, 'Answer "no" to confirmation questions; accept the default value for other questions; disable interaction'),
        ]);
    }

    /**
     * @inheritdoc
     */
    protected function getDefaultCommands(): array
    {
        return [
            new HelpCommand($this->config),
            new ListCommand($this->config),
            new CompleteCommand(),
            new DumpCompletionCommand(),
        ];
    }

    /**
     * @inheritdoc
     */
    public function getHelp(): string
    {
        $messages = [
            $this->getLongVersion(),
            '',
            '<comment>Global options:</comment>',
        ];

        foreach ($this->getDefinition()
                      ->getOptions() as $option) {
            $messages[] = sprintf(
                '  %-29s %s %s',
                '<info>--' . $option->getName() . '</info>',
                $option->getShortcut() ? '<info>-' . $option->getShortcut() . '</info>' : '  ',
                $option->getDescription(),
            );
        }

        return implode(PHP_EOL, $messages);
    }

    /**
     * @internal
     */
    public function setIO(InputInterface $input, OutputInterface $output): void
    {
        $this->container()->set(InputInterface::class, $input);
        $this->container()->set(OutputInterface::class, $output);
    }

    /**
     * {@inheritdoc}
     */
    protected function configureIO(InputInterface $input, OutputInterface $output): void
    {
        $this->setIO($input, $output);

        // Set the input to non-interactive if the yes or no options are used,
        // or if the PLATFORMSH_CLI_NO_INTERACTION variable is not empty.
        // The --no-interaction option is handled in the parent method.
        if ($input->hasParameterOption(['--yes', '-y', '--no', '-n'])
          || getenv($this->envPrefix . 'NO_INTERACTION')) {
            $input->setInteractive(false);
        }

        // Allow the NO_COLOR, CLICOLOR_FORCE, and TERM environment variables to
        // override whether colors are used in the output.
        // See: https://no-color.org
        // See: https://en.wikipedia.org/wiki/Computer_terminal#Dumb_terminals
        /* @see StreamOutput::hasColorSupport() */
        if (getenv('CLICOLOR_FORCE') === '1') {
            $output->setDecorated(true);
        } elseif (getenv('NO_COLOR')
            || getenv('CLICOLOR_FORCE') === '0'
            || getenv('TERM') === 'dumb'
            || getenv($this->envPrefix . 'NO_COLOR')) {
            $output->setDecorated(false);
        }

        if ($input->hasParameterOption('--ansi', true)) {
            $output->setDecorated(true);
        } elseif ($input->hasParameterOption('--no-ansi', true)) {
            $output->setDecorated(false);
        }

        $stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        if ($input->hasParameterOption(['--yes', '-y', '--no-interaction', '-n', '--no'], true)
            || getenv($this->envPrefix . 'NO_INTERACTION')) {
            $input->setInteractive(false);

            // Deprecate the -n flag as a shortcut for --no.
            // It is confusing as it's a shortcut for --no-interaction in other Symfony Console commands.
            if ($input->hasParameterOption('-n', true)) {
                $stdErr->writeln('<options=reverse>DEPRECATED</> The -n flag (as a shortcut for --no) is deprecated. It will be removed or changed in a future version.');
            }
        } elseif (\function_exists('posix_isatty') && $input instanceof ArgvInput && defined('STDIN')) {
            if (!@posix_isatty(STDIN) && false === getenv('SHELL_INTERACTIVE')) {
                $input->setInteractive(false);
            }
        }

        switch ($shellVerbosity = (int) getenv('SHELL_VERBOSITY')) {
            case -2: $output->setVerbosity(OutputInterface::VERBOSITY_SILENT);
                break;
            case -1: $stdErr->setVerbosity(OutputInterface::VERBOSITY_QUIET);
                break;
            case 1: $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
                break;
            case 2: $output->setVerbosity(OutputInterface::VERBOSITY_VERY_VERBOSE);
                break;
            case 3: $output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
                break;
            default: $shellVerbosity = 0;
                break;
        }

        if (true === $input->hasParameterOption(['--silent'], true)) {
            $output->setVerbosity(OutputInterface::VERBOSITY_SILENT);
            $shellVerbosity = -2;
        } elseif (true === $input->hasParameterOption(['--quiet', '-q'], true)) {
            $stdErr->setVerbosity(OutputInterface::VERBOSITY_QUIET);
            $shellVerbosity = -1;
        } else {
            if ($input->hasParameterOption('-vvv', true) || $input->hasParameterOption('--verbose=3', true) || 3 === $input->getParameterOption('--verbose', false, true)) {
                $output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
                $shellVerbosity = 3;
            } elseif ($input->hasParameterOption('-vv', true) || $input->hasParameterOption('--verbose=2', true) || 2 === $input->getParameterOption('--verbose', false, true)) {
                $output->setVerbosity(OutputInterface::VERBOSITY_VERY_VERBOSE);
                $shellVerbosity = 2;
            } elseif ($input->hasParameterOption('-v', true) || $input->hasParameterOption('--verbose=1', true) || $input->hasParameterOption('--verbose', true) || $input->getParameterOption('--verbose', false, true)) {
                $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
                $shellVerbosity = 1;
            }
        }

        if (getenv($this->envPrefix . 'DEBUG')) {
            $output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
            $shellVerbosity = 3;
        }

        if (0 > $shellVerbosity) {
            $input->setInteractive(false);
        }

        if (\function_exists('putenv')) {
            @putenv('SHELL_VERBOSITY=' . $shellVerbosity);
        }
        $_ENV['SHELL_VERBOSITY'] = $shellVerbosity;
        $_SERVER['SHELL_VERBOSITY'] = $shellVerbosity;

        // Turn off error reporting in quiet mode.
        if ($shellVerbosity === -1) {
            error_reporting(0);
            ini_set('display_errors', '0');
        } else {
            // Display errors by default. In verbose mode, display all PHP
            // error levels except deprecations. Deprecations will only be
            // displayed while in debug mode and only if this is enabled via
            // the CLI_REPORT_DEPRECATIONS environment variable.
            $error_level = ($shellVerbosity >= 1 ? E_ALL : E_PARSE | E_ERROR) & ~E_DEPRECATED;
            $report_deprecations = getenv('CLI_REPORT_DEPRECATIONS') || getenv($this->envPrefix . 'REPORT_DEPRECATIONS');
            if ($report_deprecations && $shellVerbosity >= 3) {
                $error_level |= E_DEPRECATED;
            }
            error_reporting($error_level);
            ini_set('display_errors', 'stderr');
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function doRunCommand(ConsoleCommand $command, InputInterface $input, OutputInterface $output): int
    {
        if (!$command->isEnabled()) {
            throw new \InvalidArgumentException(sprintf('The command "%s" is not enabled.', $command->getName()));
        }

        if ($command instanceof MultiAwareInterface) {
            $command->setRunningViaMulti($this->runningViaMulti);
        }

        // Work around a bug in Console which means the default command's input
        // is always considered to be interactive.
        if ($command->getName() === 'welcome'
            && isset($GLOBALS['argv'])
            && array_intersect($GLOBALS['argv'], ['-n', '--no', '-y', '---yes'])) {
            $input->setInteractive(false);
        }

        // Check for automatic updates.
        $noChecks = $command->getName() == '_completion';
        $container = $this->container();
        if ($input->isInteractive() && !$noChecks) {
            /** @var SelfUpdateChecker $checker */
            $checker = $container->get(SelfUpdateChecker::class);
            $checker->checkUpdates();
        }

        if (!$noChecks && $command->getName() !== 'legacy-migrate') {
            /** @var LegacyMigration $legacyMigration */
            $legacyMigration = $container->get(LegacyMigration::class);
            $legacyMigration->checkMigrateFrom3xTo4x();
            $legacyMigration->checkMigrateToGoWrapper();
        }

        if (!$noChecks && $command->getName() !== 'self::install') {
            /** @var SelfInstallChecker $selfInstallChecker */
            $selfInstallChecker = $container->get(SelfInstallChecker::class);
            $selfInstallChecker->checkSelfInstall();
        }

        return parent::doRunCommand($command, $input, $output);
    }

    public function setRunningViaMulti(): void
    {
        $this->runningViaMulti = true;
    }

    public function getLongVersion(): string
    {
        // Show "(legacy)" in the version output, if not wrapped.
        if (!$this->config->isWrapped() && $this->config->getBool('application.mark_unwrapped_legacy')) {
            return sprintf('%s (legacy) <info>%s</info>', $this->config->getStr('application.name'), $this->config->getVersion());
        }
        return sprintf('%s <info>%s</info>', $this->config->getStr('application.name'), $this->config->getVersion());
    }
}
