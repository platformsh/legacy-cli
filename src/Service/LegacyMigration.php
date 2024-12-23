<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Local\LocalProject;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LegacyMigration
{
    private static bool $promptedDeleteOldCli = false;
    private static bool $checkedMigrateToGoWrapper = false;

    private readonly OutputInterface $stdErr;

    public function __construct(
        private readonly Config           $config,
        private readonly InputInterface   $input,
        private readonly InstallationInfo $installationInfo,
        private readonly Io               $io,
        private readonly LocalProject     $localProject,
        private readonly QuestionHelper   $questionHelper,
        private readonly SubCommandRunner $subCommandRunner,
        private readonly State            $state,
        OutputInterface                   $output,
    ) {
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /**
     * Check and prompt the user to remove the old PHP installation and migrate to the new Go-wrapped one.
     *
     * @return void
     */
    public function checkMigrateToGoWrapper(): void
    {
        // Run migration steps if configured.
        if ($this->config->getBool('migrate.prompt')) {
            $this->promptDeleteOldCli();
            $this->checkMigrateToNewCLI();
        }
    }

    /**
     * Prompt the user to migrate from the legacy project file structure.
     *
     * If the input is interactive, the user will be asked to migrate up to once
     * per hour. The time they were last asked will be stored in the project
     * configuration. If the input is not interactive, the user will be warned
     * (on every command run) that they should run the 'legacy-migrate' command.
     */
    public function checkMigrateFrom3xTo4x(): void
    {
        static $asked = false;
        if (!$this->localProject->getLegacyProjectRoot()) {
            $asked = true;
            return;
        }
        if ($asked) {
            return;
        }
        $asked = true;

        $projectRoot = $this->localProject->getProjectRoot();
        $timestamp = time();
        $promptMigrate = true;
        if ($projectRoot) {
            $projectConfig = $this->localProject->getProjectConfig($projectRoot);
            if (isset($projectConfig['migrate']['3.x']['last_asked'])
                && $projectConfig['migrate']['3.x']['last_asked'] > $timestamp - 3600) {
                $promptMigrate = false;
            }
        }

        $this->stdErr->writeln(sprintf(
            'You are in a project using an old file structure, from previous versions of the %s.',
            $this->config->getStr('application.name'),
        ));
        if ($this->input->isInteractive() && $promptMigrate) {
            if ($projectRoot && is_array($projectConfig)) {
                $projectConfig['migrate']['3.x']['last_asked'] = $timestamp;
                /** @noinspection PhpUnhandledExceptionInspection */
                $this->localProject->writeCurrentProjectConfig($projectConfig, $projectRoot);
            }
            if ($this->questionHelper->confirm('Migrate to the new structure?')) {
                $code = $this->subCommandRunner->run('legacy-migrate');
                exit($code);
            }
        } else {
            $this->stdErr->writeln(sprintf(
                'Fix this with: <comment>%s legacy-migrate</comment>',
                $this->config->getStr('application.executable'),
            ));
        }
        $this->stdErr->writeln('');
    }

    /**
     * Check if both CLIs are installed to prompt the user to delete the old one.
     */
    private function promptDeleteOldCli(): void
    {
        // Avoid checking more than once in this process.
        if (self::$promptedDeleteOldCli) {
            return;
        }
        self::$promptedDeleteOldCli = true;

        if ($this->config->isWrapped() || !$this->installationInfo->otherCLIsInstalled()) {
            return;
        }
        $pharPath = \Phar::running(false);
        if (!$pharPath || !is_file($pharPath) || !is_writable($pharPath)) {
            return;
        }

        // Avoid deleting random directories in path
        $legacyDir = dirname($pharPath, 2);
        if ($legacyDir !== $this->config->getUserConfigDir()) {
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
        if ($this->questionHelper->confirm('Do you want to remove this file now?')) {
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
    private function checkMigrateToNewCLI(): void
    {
        // Avoid checking more than once in this process.
        if (self::$checkedMigrateToGoWrapper) {
            return;
        }
        self::$checkedMigrateToGoWrapper = true;

        // Avoid if running within the new CLI or within a CI.
        if ($this->config->isWrapped() || $this->isCI()) {
            return;
        }

        // Prompt the user to migrate at most once every 24 hours.
        $now = time();
        $embargoTime = $now - $this->config->getWithDefault('migrate.prompt_interval', 60 * 60 * 24);
        if ($this->state->get('migrate.last_prompted') > $embargoTime) {
            return;
        }

        $message = "<options=bold;fg=yellow>Warning:</>"
            . "\nRunning the CLI directly under PHP is now referred to as the \"Legacy CLI\", and is no longer recommended.";
        if ($this->config->has('migrate.docs_url')) {
            $message .= "\nInstall the latest release for your operating system by following these instructions: "
                . "\n" . $this->config->getStr('migrate.docs_url');
        }
        $message .= "\n";
        $this->stdErr->writeln($message);
        $this->state->set('migrate.last_prompted', time());
    }

    /**
     * Detects if running within a CI or local container system.
     *
     * @return bool
     */
    private function isCI(): bool
    {
        return getenv('CI') !== false // GitHub Actions, Travis CI, CircleCI, Cirrus CI, GitLab CI, AppVeyor, CodeShip, dsari
            || getenv('BUILD_NUMBER') !== false // Jenkins, TeamCity
            || getenv('RUN_ID') !== false // TaskCluster, dsari
            || getenv('LANDO_INFO') !== false // Lando (https://docs.lando.dev/guides/lando-info.html)
            || getenv('IS_DDEV_PROJECT') === 'true' // DDEV (https://ddev.readthedocs.io/en/latest/users/extend/custom-commands/#environment-variables-provided)
            || $this->detectRunningInHook(); // PSH
    }

    /**
     * Detects a Platform.sh non-terminal Dash environment; i.e. a hook.
     *
     * @todo deduplicate this
     *
     * @return bool
     */
    private function detectRunningInHook(): bool
    {
        $envPrefix = $this->config->getStr('service.env_prefix');
        if (getenv($envPrefix . 'PROJECT')
            && basename((string) getenv('SHELL')) === 'dash'
            && !$this->io->isTerminal(STDIN)) {
            return true;
        }

        return false;
    }
}
