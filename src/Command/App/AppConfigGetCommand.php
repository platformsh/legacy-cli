<?php
namespace Platformsh\Cli\Command\App;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppConfigGetCommand extends CommandBase
{
    protected static $defaultName = 'app:config-get';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setDescription('View the configuration of an app')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The configuration property to view')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');
        $this->addProjectOption();
        $this->addEnvironmentOption();
        $this->addAppOption();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $appConfig = $this->api()
            ->getCurrentDeployment($this->getSelectedEnvironment(), $input->getOption('refresh'))
            ->getWebApp($this->selectApp($input))
            ->getProperties();

        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');
        $formatter->displayData($output, $appConfig, $input->getOption('property'));
    }
}
