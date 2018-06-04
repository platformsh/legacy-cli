<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\App;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppConfigGetCommand extends CommandBase
{
    protected static $defaultName = 'app:config-get';

    private $api;
    private $selector;
    private $formatter;

    public function __construct(Api $api, Selector $selector, PropertyFormatter $formatter)
    {
        $this->api = $api;
        $this->selector = $selector;
        $this->formatter = $formatter;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setDescription('View the configuration of an app')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The configuration property to view')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');
        $this->selector->addAllOptions($this->getDefinition());
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);

        $appConfig = $this->api
            ->getCurrentDeployment($selection->getEnvironment(), $input->getOption('refresh'))
            ->getWebApp($selection->getAppName())
            ->getProperties();

        $this->formatter->displayData($output, $appConfig, $input->getOption('property'));
    }
}
