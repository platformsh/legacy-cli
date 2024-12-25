<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Humbug\SelfUpdate\Updater;
use Platformsh\Cli\SelfUpdate\ManifestStrategy;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SelfUpdater
{
    protected OutputInterface $stdErr;

    protected int $timeout = 30;
    protected bool $allowUnstable = false;
    protected bool $allowMajor = false;

    /**
     * Updater constructor.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @param Config $config
     * @param QuestionHelper  $questionHelper
     */
    public function __construct(
        protected InputInterface $input,
        protected OutputInterface $output,
        protected Config $config,
        protected QuestionHelper $questionHelper,
    ) {
        $this->stdErr = $this->output instanceof ConsoleOutputInterface
            ? $this->output->getErrorOutput()
            : $this->output;
    }

    /**
     * Set the timeout for the version check.
     *
     * @param int $timeout
     *   The timeout in seconds.
     */
    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
    }

    /**
     * Set the updater to allow unstable versions.
     *
     * @param bool $allowUnstable
     */
    public function setAllowUnstable(bool $allowUnstable = true): void
    {
        $this->allowUnstable = $allowUnstable;
    }

    /**
     * Set the updater to allow major version updates.
     *
     * @param bool $allowMajor
     */
    public function setAllowMajor(bool $allowMajor = true): void
    {
        $this->allowMajor = $allowMajor;
    }

    /**
     * Run the update.
     *
     * @param string|null $manifestUrl
     * @param string|null $currentVersion
     *
     * @return false|string
     *   The new version number, or an empty string if there was no update, or false on error.
     */
    public function update(?string $manifestUrl = null, ?string $currentVersion = null): string|false
    {
        $currentVersion = $currentVersion ?: $this->config->getVersion();
        $manifestUrl = $manifestUrl ?: $this->config->getStr('application.manifest_url');
        $applicationName = $this->config->getStr('application.name');
        if (!extension_loaded('Phar') || !($localPhar = \Phar::running(false))) {
            $this->stdErr->writeln(sprintf(
                'This instance of the %s was not installed as a Phar archive.',
                $applicationName,
            ));

            // Instructions for users who are running a global Composer install.
            if (defined('CLI_ROOT') && file_exists(CLI_ROOT . '/../../autoload.php')) {
                $this->stdErr->writeln("Update using:\n\n  composer global update");
                if ($this->config->has('application.package_name')) {
                    $this->stdErr->writeln("\nOr you can switch to a Phar install (<options=bold>recommended</>):\n");
                    $this->stdErr->writeln("  composer global remove " . $this->config->getStr('application.package_name'));
                    $this->stdErr->writeln("  curl -sS " . $this->config->getStr('application.installer_url') . " | php\n");
                } else {
                    $this->stdErr->writeln("\nOr you can switch to a Phar install (<options=bold>recommended</>)\n");
                }
            }

            return false;
        }

        $this->stdErr->writeln(sprintf(
            'Checking for %s updates (current version: <info>%s</info>)',
            $applicationName,
            $currentVersion,
        ));

        if (!is_writable($localPhar)) {
            $this->stdErr->writeln('Cannot update as the Phar file is not writable: ' . $localPhar);

            return false;
        }
        if (!is_writable(dirname($localPhar))) {
            $this->stdErr->writeln('Cannot update as the directory is not writable: ' . dirname($localPhar));

            return false;
        }

        $updater = new Updater($localPhar, false);
        $strategy = new ManifestStrategy(ltrim($currentVersion, 'v'), $manifestUrl, $this->allowMajor, $this->allowUnstable);
        $strategy->setManifestTimeout($this->timeout);
        $strategy->setStreamContextOptions($this->config->getStreamContextOptions());
        $updater->setStrategyObject($strategy);

        if (!$updater->hasUpdate()) {
            $this->stdErr->writeln('No updates found');
            return '';
        }

        $newVersionString = $updater->getNewVersion();

        // Some dev versions cannot be compared against other version numbers,
        // so do not check for release notes in that case.
        $currentIsDev = str_starts_with($currentVersion, 'dev-');

        if (!$currentIsDev && ($notes = $strategy->getUpdateNotesByVersion($currentVersion, $newVersionString))) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf('Version <info>%s</info> is available. Release notes:', $newVersionString));
            foreach ($notes as $version => $notesStr) {
                if (\count($notes) > 1) {
                    $this->stdErr->writeln('<comment>' . $version . '</comment>:');
                }
                $this->stdErr->writeln(preg_replace('/^/m', '  ', $notesStr));
                $this->stdErr->writeln('');
            }
        }

        if (!$this->questionHelper->confirm(sprintf('Update to version <info>%s</info>?', $newVersionString))) {
            return false;
        }

        $this->stdErr->writeln(sprintf('Updating to version %s', $newVersionString));

        $updater->update();

        $this->stdErr->writeln(sprintf(
            'The %s has been successfully updated to version <info>%s</info>',
            $applicationName,
            $newVersionString,
        ));

        return $newVersionString;
    }
}
