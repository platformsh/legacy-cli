<?php
namespace Platformsh\Cli\Command\Self;

use Platformsh\Cli\Command\CommandBase;
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
        $manifestUrl = $input->getOption('manifest') ?: $this->config()->get('application.manifest_url');
        $currentVersion = $input->getOption('current-version') ?: $this->config()->get('application.version');

        /** @var \Platformsh\Cli\Service\SelfUpdater $cliUpdater */
        $cliUpdater = $this->getService('self_updater');
        $cliUpdater->setAllowMajor(!$input->getOption('no-major'));
        $cliUpdater->setAllowUnstable((bool) $input->getOption('unstable'));
        $cliUpdater->setTimeout($input->getOption('timeout'));

        $result = $cliUpdater->update($manifestUrl, $currentVersion);
        if ($result === false) {
            return 1;
        }

        // Phar cannot load more classes after the update has occurred. So to
        // avoid errors from classes loaded after this (e.g.
        // ConsoleTerminateEvent), we exit directly now.
        exit(0);
    }

    /**
     * {@inheritdoc}
     */
    protected function checkUpdates()
    {
        // Don't check for updates automatically when running self-update.
    }
}
