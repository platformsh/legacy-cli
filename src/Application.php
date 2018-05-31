<?php
namespace Platformsh\Cli;

use Platformsh\Cli\Console\EventSubscriber;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\LegacyMigration;
use Platformsh\Cli\Service\SelfUpdateChecker;
use Platformsh\Cli\Util\TimezoneUtil;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Application as ParentApplication;
use Symfony\Component\Console\Command\Command as ConsoleCommand;
use Symfony\Component\Console\DependencyInjection\AddConsoleCommandPass;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Exception\InvalidArgumentException as ConsoleInvalidArgumentException;
use Symfony\Component\Console\Exception\InvalidOptionException as ConsoleInvalidOptionException;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\EventDispatcher\EventDispatcher;

class Application extends ParentApplication
{
    /**
     * @var ConsoleCommand|null
     */
    private $currentCommand;

    /** @var Config */
    private $config;

    /** @var \Symfony\Component\DependencyInjection\Container */
    private static $container;

    /**
     * {@inheritdoc}
     */
    public function __construct(Config $config = null)
    {
        $this->config = $config ?: new Config();
        parent::__construct($this->config->get('application.name'), $this->config->get('application.version'));

        // Use the configured timezone, or fall back to the system timezone.
        date_default_timezone_set(
            $this->config->getWithDefault('application.timezone', null)
                ?: TimezoneUtil::getTimezone()
        );

        self::container()->set(__CLASS__, $this);

        $this->setCommandLoader(self::container()->get('console.command_loader'));

        $this->setDefaultCommand('welcome');

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new EventSubscriber($this->config));
        $this->setDispatcher($dispatcher);
    }

    /**
     * {@inheritdoc}
     *
     * Prevent commands being enabled, according to config.yaml configuration.
     */
    public function add(ConsoleCommand $command)
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
     * @return ContainerInterface
     */
    private static function container(): ContainerInterface
    {
        $cacheFile = __DIR__ . '/../config/cache/container.php';
        $servicesFile = __DIR__ . '/../config/services.yaml';

        if (!isset(self::$container)) {
            if (file_exists($cacheFile) && !getenv('PLATFORMSH_CLI_DEBUG')) {
                // Load the cached container.
                /** @noinspection PhpIncludeInspection */
                require_once $cacheFile;
                /** @noinspection PhpUndefinedClassInspection */
                self::$container = new \ProjectServiceContainer();
            } else {
                // Compile a new container.
                self::$container = new ContainerBuilder();
                try {
                    (new YamlFileLoader(self::$container, new FileLocator()))
                        ->load($servicesFile);
                } catch (\Exception $e) {
                    throw new \RuntimeException(sprintf(
                        'Failed to load services.yaml file %s: %s',
                        $servicesFile,
                        $e->getMessage()
                    ));
                }
                self::$container->addCompilerPass(new AddConsoleCommandPass());
                self::$container->compile();
                $dumper = new PhpDumper(self::$container);
                if (!is_dir(dirname($cacheFile))) {
                    mkdir(dirname($cacheFile), 0755, true);
                }
                file_put_contents($cacheFile, $dumper->dump());
            }
        }

        return self::$container;
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultInputDefinition()
    {
        // We remove the confusing `--ansi` and `--no-ansi` options.
        return new InputDefinition([
            new InputArgument('command', InputArgument::REQUIRED, 'The command to execute'),
            new InputOption('--help', '-h', InputOption::VALUE_NONE, 'Display this help message'),
            new InputOption('--quiet', '-q', InputOption::VALUE_NONE, 'Do not output any message'),
            new InputOption('--verbose', '-v|vv|vvv', InputOption::VALUE_NONE, 'Increase the verbosity of messages'),
            new InputOption('--version', '-V', InputOption::VALUE_NONE, 'Display this application version'),
            new InputOption('--yes', '-y', InputOption::VALUE_NONE, 'Answer "yes" to all prompts; disable interaction'),
            new InputOption('--no', '-n', InputOption::VALUE_NONE, 'Answer "no" to all prompts'),
        ]);
    }

    /**
     * @inheritdoc
     */
    protected function getDefaultCommands()
    {
        // Override the default commands to add a custom HelpCommand and
        // ListCommand.
        return [new Command\HelpCommand(), new Command\ListCommand()];
    }

    /**
     * @inheritdoc
     */
    public function getHelp()
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
                $option->getDescription()
            );
        }

        return implode(PHP_EOL, $messages);
    }

    /**
     * {@inheritdoc}
     */
    protected function configureIO(InputInterface $input, OutputInterface $output)
    {
        // Set the input and output in the service container.
        self::container()->set('input', $input);
        self::container()->set('output', $output);

        parent::configureIO($input, $output);

        // Set the input to non-interactive if the yes or no options are used.
        if ($input->hasParameterOption(['--yes', '-y', '--no', '-n'])) {
            $input->setInteractive(false);
        }

        // Allow the CLICOLOR_FORCE environment variable to override whether
        // colors are used in the output.
        /* @see StreamOutput::hasColorSupport() */
        if (getenv('CLICOLOR_FORCE') === '0') {
            $output->setDecorated(false);
        } elseif (getenv('CLICOLOR_FORCE') === '1') {
            $output->setDecorated(true);
        }

        // The api.debug config option triggers debug-level output.
        if ($this->config->get('api.debug')) {
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
    }

    /**
     * {@inheritdoc}
     */
    protected function doRunCommand(ConsoleCommand $command, InputInterface $input, OutputInterface $output)
    {
        $this->setCurrentCommand($command);

        // Build the command synopsis early, so it doesn't include default
        // options and arguments (such as --help and <command>).
        // @todo find a better solution for this?
        $this->currentCommand->getSynopsis();

        // Work around a bug in Console which means the default command's input
        // is always considered to be interactive.
        if ($command->getName() === 'welcome'
            && isset($GLOBALS['argv'])
            && array_intersect($GLOBALS['argv'], ['-n', '--no', '-y', '---yes'])) {
            $input->setInteractive(false);
        }

        // Check for automatic updates.
        $noChecks = in_array($command->getName(), ['welcome', '_completion']);
        if ($input->isInteractive() && !$noChecks) {
            /** @var SelfUpdateChecker $checker */
            $checker = self::container()->get(SelfUpdateChecker::class);
            $checker->checkUpdates();
        }

        if (!$noChecks && $command->getName() !== 'legacy-migrate') {
            /** @var LocalProject $localProject */
            $localProject = self::container()->get(LocalProject::class);
            if ($localProject->getLegacyProjectRoot()) {
                /** @var LegacyMigration $legacyMigration */
                $legacyMigration = self::container()->get(LegacyMigration::class);
                $legacyMigration->check();
            }
        }

        return parent::doRunCommand($command, $input, $output);
    }

    /**
     * Set the current command. This is used for error handling.
     *
     * @param ConsoleCommand|null $command
     */
    public function setCurrentCommand(ConsoleCommand $command = null)
    {
        // The parent class has a similar (private) property named
        // $runningCommand.
        $this->currentCommand = $command;
    }

    /**
     * Get the current command.
     *
     * @return ConsoleCommand|null
     */
    public function getCurrentCommand()
    {
        return $this->currentCommand;
    }

    /**
     * {@inheritdoc}
     */
    public function renderException(\Exception $e, OutputInterface $output)
    {
        $output->writeln('', OutputInterface::VERBOSITY_QUIET);

        $this->doRenderException($e, $output);

        if (isset($this->currentCommand)
            && $this->currentCommand->getName() !== 'welcome'
            && ($e instanceof ConsoleInvalidArgumentException
                || $e instanceof ConsoleInvalidOptionException
                || $e instanceof ConsoleRuntimeException
            )) {
            $output->writeln(
                sprintf('Usage: <info>%s</info>', $this->currentCommand->getSynopsis()),
                OutputInterface::VERBOSITY_QUIET
            );
            $output->writeln('', OutputInterface::VERBOSITY_QUIET);
            $output->writeln(sprintf(
                'For more information, type: <info>%s help %s</info>',
                $this->config->get('application.executable'),
                $this->currentCommand->getName()
            ), OutputInterface::VERBOSITY_QUIET);
            $output->writeln('', OutputInterface::VERBOSITY_QUIET);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function find($name)
    {
        try {
            return parent::find($name);
        } catch (CommandNotFoundException $e) {
            // If a command is not found, load all commands so that aliases can
            // be checked.
            // @todo make aliases part of the services.yaml file to keep the performance benefit of lazy-loading?
            /** @var \Symfony\Component\Console\CommandLoader\CommandLoaderInterface $loader */
            $loader = self::$container->get('console.command_loader');
            foreach ($loader->getNames() as $loaderName) {
                if (!$this->has($loaderName)) {
                    $this->add($loader->get($loaderName));
                }
            }
            return parent::find($name);
        }
    }
}
