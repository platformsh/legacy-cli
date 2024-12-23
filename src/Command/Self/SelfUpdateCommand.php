<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Self;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\SelfUpdater;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'self:update', description: 'Update the CLI to the latest version', aliases: ['update', 'up'])]
class SelfUpdateCommand extends CommandBase
{
    public function __construct(private readonly Config $config, private readonly SelfUpdater $selfUpdater)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this
            ->setHiddenAliases(['self-update'])
            ->addOption('no-major', null, InputOption::VALUE_NONE, 'Only update between minor or patch versions')
            ->addOption('unstable', null, InputOption::VALUE_NONE, 'Update to a new unstable version, if available')
            ->addOption('manifest', null, InputOption::VALUE_REQUIRED, 'Override the manifest file location')
            ->addOption('current-version', null, InputOption::VALUE_REQUIRED, 'Override the current version')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'A timeout for the version check', 30);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manifestUrl = $input->getOption('manifest') ?: $this->config->getStr('application.manifest_url');
        $currentVersion = $input->getOption('current-version') ?: $this->config->getVersion();
        $this->selfUpdater->setAllowMajor(!$input->getOption('no-major'));
        $this->selfUpdater->setAllowUnstable((bool) $input->getOption('unstable'));
        $this->selfUpdater->setTimeout($input->getOption('timeout'));

        $result = $this->selfUpdater->update($manifestUrl, $currentVersion);
        if ($result === '') {
            return 0;
        }
        if ($result === false) {
            return 1;
        }

        // Phar cannot load more classes after the update has occurred. So to
        // avoid errors from classes loaded after this (e.g.
        // ConsoleTerminateEvent), we exit directly now.
        exit(0);
    }
}
