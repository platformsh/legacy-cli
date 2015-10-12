<?php
namespace Platformsh\Cli\Command\Self;

use Herrera\Phar\Update\Manager;
use Herrera\Phar\Update\Manifest;
use Herrera\Version\Parser;
use Platformsh\Cli\Command\PlatformCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SelfUpdateCommand extends PlatformCommand
{
    protected function configure()
    {
        $this
          ->setName('self-update')
          ->setAliases(array('up'))
          ->setDescription('Update the CLI to the latest version')
          ->addOption('major', null, InputOption::VALUE_NONE, 'Update to a new major version, if available')
          ->addOption('manifest', null, InputOption::VALUE_REQUIRED, 'Override the manifest file location')
          ->addOption('current-version', null, InputOption::VALUE_REQUIRED, 'Override the current version');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $manifestUrl = $input->getOption('manifest') ?: 'https://platform.sh/cli/manifest.json';
        $currentVersion = $input->getOption('current-version') ?: $this->getApplication()->getVersion();
        $onlyMinor = !$input->getOption('major');

        if (extension_loaded('Phar') && !($localPhar = \Phar::running(false))) {
            $this->stdErr->writeln('This instance of the CLI was not installed as a Phar archive.');
            if (file_exists(CLI_ROOT . '/../../autoload.php')) {
                $this->stdErr->writeln('Update using: <info>composer global update</info>');
            }
            return 1;
        }

        $this->stdErr->writeln("Checking for updates");

        // Download the manifest file.
        $manifest = Manifest::loadFile($manifestUrl);

        // Instantiate the update manager.
        $manager = new Manager($manifest);
        if (isset($localPhar)) {
            $manager->setRunningFile($localPhar);
        }

        // Find the most recent available version in the manifest.
        $update = $manifest->findRecent(
          Parser::toVersion($currentVersion),
          $onlyMinor
        );

        if ($update === null) {
            $this->stdErr->writeln("No updates found. The Platform.sh CLI is up-to-date.");
            return 0;
        }

        $newVersionString = $update->getVersion()->__toString();
        $this->stdErr->writeln("Updating to version $newVersionString");

        // Download the new version.
        $update->getFile();

        // Replace the current Phar with the new one.
        $update->copyTo($manager->getRunningFile());

        $this->stdErr->writeln("Successfully updated to version <info>$newVersionString</info>");

        // Errors appear if new classes are instantiated after this stage
        // (namely, Symfony's ConsoleTerminateEvent). This suggests PHP
        // can't read files properly from the overwritten Phar, or perhaps it's
        // because the autoloader's name has changed. We avoid the problem by
        // terminating now.
        exit;
    }
}
