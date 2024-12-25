<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SelfUpdateChecker
{
    private static bool $checkedUpdates = false;

    private readonly OutputInterface $stdErr;

    public function __construct(
        private readonly Config         $config,
        private readonly InputInterface $input,
        private readonly QuestionHelper $questionHelper,
        private readonly SelfUpdater    $selfUpdater,
        private readonly Shell          $shell,
        private readonly State          $state,
        OutputInterface                 $output,
    ) {
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    public function checkUpdates(): void
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
        if (!$this->config->get('updates.check')) {
            return;
        }

        // Determine an embargo time, after which updates can be checked.
        $timestamp = time();
        $embargoTime = $timestamp - $this->config->getInt('updates.check_interval');

        // Stop if updates were last checked after the embargo time.
        if ($this->state->get('updates.last_checked') > $embargoTime) {
            return;
        }

        // Stop if the Phar was updated after the embargo time.
        if (filemtime($pharFilename) > $embargoTime) {
            return;
        }

        // Ensure classes are auto-loaded if they may be needed after the
        // update.
        $currentVersion = $this->config->getVersion();

        $this->selfUpdater->setAllowMajor(true);
        $this->selfUpdater->setTimeout(5);

        try {
            $newVersion = $this->selfUpdater->update(null, $currentVersion);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Failed to download')) {
                $this->stdErr->writeln('<error>' . $e->getMessage() . '</error>');
                $newVersion = false;
            } else {
                throw $e;
            }
        }

        $this->state->set('updates.last_checked', $timestamp);

        if ($newVersion === '') {
            // No update was available.
            return;
        }

        if ($newVersion !== false) {
            // Update succeeded. Continue (based on a few conditions).
            $exitCode = 0;
            [$currentMajorVersion, ] = explode('.', $currentVersion, 2);
            [$newMajorVersion, ] = explode('.', $newVersion, 2);
            if ($newMajorVersion === $currentMajorVersion
                && $this->input instanceof ArgvInput
                && is_executable($pharFilename)) {
                $originalCommand = $this->input->__toString();
                if (empty($originalCommand)) {
                    $exitCode = $this->shell->executeSimple(escapeshellarg($pharFilename));
                    exit($exitCode);
                }

                $questionText = "\n"
                    . 'Original command: <info>' . $originalCommand . '</info>'
                    . "\n\n" . 'Continue?';
                if ($this->questionHelper->confirm($questionText)) {
                    $this->stdErr->writeln('');
                    $exitCode = $this->shell->executeSimple(escapeshellarg($pharFilename) . ' ' . $originalCommand);
                }
            }
            exit($exitCode);
        }

        // Automatic update failed.
        // Error messages will already have been printed, and the original
        // command can continue.
        $this->stdErr->writeln('');
    }
}
