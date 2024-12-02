<?php
namespace Platformsh\Cli\Command\Metrics;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\CurlCli;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'metrics:curl', description: "Run an authenticated cURL request on an environment's metrics API")]
class CurlCommand extends MetricsCommandBase
{
    protected bool $hiddenInList = true;
    public function __construct(private readonly Api $api, private readonly CurlCli $curlCli, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure()
    {
        CurlCli::configureInput($this->getDefinition());

        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(selectDefaultEnv: true));

        // Initialize the API service so that it gets CommandBase's event listeners
        // (allowing for auto login).
        $this->api;

        $link = $this->getMetricsLink($selection->getEnvironment());
        if (!$link) {
            return 1;
        }

        $curl = $this->curlCli;

        return $curl->run($link['href'], $input, $output);
    }
}
