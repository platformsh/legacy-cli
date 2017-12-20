<?php
namespace Platformsh\Cli\Command\App;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppConfigGetCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('app:config-get')
            ->setDescription('View the configuration of an app')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The configuration property to view')
            ->addOption('refresh', null, InputOption::VALUE_NONE, '[Deprecated option, no longer used]');
        $this->addProjectOption();
        $this->addEnvironmentOption();
        $this->addAppOption();
        $this->addOption('identity-file', 'i', InputOption::VALUE_REQUIRED, '[Deprecated option, no longer used]');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $this->warnAboutDeprecatedOptions(['refresh', 'identity-file']);

        /** @var \Platformsh\Cli\Service\RemoteApps $appsService */
        $appsService = $this->getService('remote_apps');

        $appConfig = $appsService->getApp($this->getSelectedEnvironment(), $this->selectApp($input))
            ->getProperties();

        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');
        $formatter->displayData($output, $appConfig, $input->getOption('property'));
    }
}
