<?php
namespace Platformsh\Cli\Command\Self;

use Herrera\Phar\Update\Manager;
use Herrera\Phar\Update\Manifest;
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
          ->addOption('manifest', null, InputOption::VALUE_OPTIONAL, 'Override the manifest file location')
          ->addOption('current-version', null, InputOption::VALUE_OPTIONAL, 'Override the current version');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $manifest = $input->getOption('manifest') ?: 'https://platform.sh/cli/manifest.json';
        $currentVersion = $input->getOption('current-version') ?: $this->getApplication()->getVersion();

        if (extension_loaded('Phar') && !($localPhar = \Phar::running(false))) {
            $this->stdErr->writeln('This instance of the CLI was not installed as a Phar archive.');
            if (file_exists(CLI_ROOT . '/../../autoload.php')) {
                $this->stdErr->writeln('Update using: <info>composer global update</info>');
            }
            return 1;
        }

        $manager = new Manager(Manifest::loadFile($manifest));
        if (isset($localPhar)) {
            $manager->setRunningFile($localPhar);
        }

        $onlyMinor = !$input->getOption('major');

        $updated = $manager->update($currentVersion, $onlyMinor);
        if ($updated) {
            $this->stdErr->writeln("Successfully updated");
            $localPhar = $manager->getRunningFile();
            passthru('php ' . escapeshellarg($localPhar) . ' --version');
        }
        else {
            $this->stdErr->writeln("No updates found. The Platform.sh CLI is up-to-date.");
        }

        return 0;
    }
}
