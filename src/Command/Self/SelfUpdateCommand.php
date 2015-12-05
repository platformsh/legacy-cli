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
          ->setName('self-update')
          ->setAliases(['up'])
          ->setDescription('Update the CLI to the latest version')
          ->addOption('major', null, InputOption::VALUE_NONE, 'Update to a new major version, if available')
          ->addOption('manifest', null, InputOption::VALUE_REQUIRED, 'Override the manifest file location')
          ->addOption('current-version', null, InputOption::VALUE_REQUIRED, 'Override the current version');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $manifestUrl = $input->getOption('manifest') ?: 'https://platform.sh/cli/manifest.json';
        $currentVersion = $input->getOption('current-version') ?: $this->getApplication()->getVersion();
        $allowMajor = $input->getOption('major');

        if (!extension_loaded('Phar') || !($localPhar = \Phar::running(false))) {
            $this->stdErr->writeln('This instance of the CLI was not installed as a Phar archive.');
            if (file_exists(CLI_ROOT . '/../../autoload.php')) {
                $this->stdErr->writeln("Update using:\n  composer global update");
            }
            $this->stdErr->writeln("\nYou can switch to a Phar install (recommended):");
            $this->stdErr->writeln("  curl -sS https://platform.sh/cli/installer | php");
            return 1;
        }

        $this->stdErr->writeln(sprintf('Checking for updates (current version: <info>%s</info>)', $currentVersion));

        $updater = new Updater(null, false);
        $strategy = new ManifestStrategy($currentVersion, $allowMajor, $manifestUrl);
        $updater->setStrategyObject($strategy);

        if (!$updater->hasUpdate()) {
            $this->stdErr->writeln('No updates found');
            return 0;
        }

        $newVersionString = $updater->getNewVersion();
        /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');
        if (!$questionHelper->confirm(sprintf('Update to version %s?', $newVersionString), $input, $output)) {
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
}
