<?php
namespace Platformsh\Cli\Command\Self;

use Humbug\SelfUpdate\Updater;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\SelfUpdate\ManifestStrategy;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SelfUpdateCommand extends CommandBase
{
    protected function configure()
    {
        $this
            ->setName('self:update')
            ->setAliases(['self-update'])
            ->setDescription('Update the CLI to the latest version')
            ->addOption('no-major', null, InputOption::VALUE_NONE, 'Only update between minor or patch versions')
            ->addOption('unstable', null, InputOption::VALUE_NONE, 'Update to a new unstable version, if available')
            ->addOption('manifest', null, InputOption::VALUE_REQUIRED, 'Override the manifest file location')
            ->addOption('current-version', null, InputOption::VALUE_REQUIRED, 'Override the current version')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'A timeout for the version check', 30);
        $this->setHiddenAliases(['update']);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $manifestUrl = $input->getOption('manifest') ?: CLI_UPDATE_MANIFEST_URL;
        $currentVersion = $input->getOption('current-version') ?: CLI_VERSION;
        $allowMajor = !$input->getOption('no-major');
        $allowUnstable = $input->getOption('unstable');

        if (!extension_loaded('Phar') || !($localPhar = \Phar::running(false))) {
            $this->stdErr->writeln('This instance of the CLI was not installed as a Phar archive.');

            // Instructions for users who are running a global Composer install.
            if (file_exists(CLI_ROOT . '/../../autoload.php')) {
                $this->stdErr->writeln("Update using:\n\n  composer global update");
                $this->stdErr->writeln("\nOr you can switch to a Phar install (<options=bold>recommended</>):\n");
                $this->stdErr->writeln("  composer global remove " . CLI_PACKAGE_NAME);
                $this->stdErr->writeln("  curl -sS " . CLI_INSTALLER_URL . " | php\n");
            }
            return 1;
        }

        $this->stdErr->writeln(sprintf('Checking for updates (current version: <info>%s</info>)', $currentVersion));

        $updater = new Updater(null, false);
        $strategy = new ManifestStrategy($currentVersion, $manifestUrl, $allowMajor, $allowUnstable);
        $strategy->setManifestTimeout((int) $input->getOption('timeout'));
        $updater->setStrategyObject($strategy);

        if (!$updater->hasUpdate()) {
            $this->stdErr->writeln('No updates found');
            return 0;
        }

        $newVersionString = $updater->getNewVersion();

        if ($notes = $strategy->getUpdateNotes($updater)) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf('Version <info>%s</info> is available. Update notes:', $newVersionString));
            $this->stdErr->writeln(preg_replace('/^/m', '  ', $notes));
            $this->stdErr->writeln('');
        }

        /** @var \Platformsh\Cli\Helper\QuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');
        if (!$questionHelper->confirm(sprintf('Update to version %s?', $newVersionString))) {
            return 1;
        }

        $this->stdErr->writeln(sprintf('Updating to version %s', $newVersionString));

        $updater->update();

        $this->stdErr->writeln("Successfully updated to version <info>$newVersionString</info>");

        // Errors appear if new classes are instantiated after this stage
        // (namely, Symfony's ConsoleTerminateEvent). This suggests PHP
        // can't read files properly from the overwritten Phar, or perhaps it's
        // because the autoloader's name has changed. We avoid the problem by
        // terminating now.
        exit;
    }

    /**
     * {@inheritdoc}
     */
    protected function checkUpdates($reset = false)
    {
        // Don't check for updates automatically when running self-update.
    }
}
