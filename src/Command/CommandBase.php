<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Application;
use Platformsh\Cli\Event\EnvironmentsChangedEvent;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Drush;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\SubCommandRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class CommandBase extends Command implements MultiAwareInterface
{
    use HasExamplesTrait;

    /** @var OutputInterface|null */
    protected $stdErr;

    protected $runningViaMulti = false;

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
        $localProject = Application::container()->get(LocalProject::class);
        if ($localProject->getLegacyProjectRoot() && $this->getName() !== 'legacy-migrate' && !$asked) {
            $asked = true;

            $projectRoot = $localProject->getProjectRoot();
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
                $questionHelper = Application::container()->get(QuestionHelper::class);
                if ($questionHelper->confirm('Migrate to the new structure?')) {
                    /** @var \Platformsh\Cli\Service\SubCommandRunner $subCommandRunner */
                    $subCommandRunner = Application::container()->get(SubCommandRunner::class);
                    $code = $subCommandRunner->run('legacy-migrate');
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
        /** @var \Platformsh\Cli\Service\Selector $selector */
        $selector = Application::container()->get(Selector::class);
        $projectRoot = $selector->getProjectRoot();
        if (!$projectRoot) {
            return;
        }
        // Make sure the local:drush-aliases command is enabled.
        if (!$this->getApplication()->has('local:drush-aliases')) {
            return;
        }
        // Double-check that the passed project is the current one.
        $currentProject = $selector->getCurrentProject();
        if (!$currentProject || $currentProject->id != $event->getProject()->id) {
            return;
        }
        // Ignore the project if it doesn't contain a Drupal application.
        if (!Drupal::isDrupal($projectRoot)) {
            return;
        }
        /** @var Drush $drush */
        $drush = Application::container()->get(Drush::class);
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
     * Get the configuration service.
     *
     * @return \Platformsh\Cli\Service\Config
     */
    private function config()
    {
        return Application::container()->get(Config::class);
    }

    /**
     * {@inheritdoc}
     */
    public function canBeRunMultipleTimes()
    {
        return true;
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
}
