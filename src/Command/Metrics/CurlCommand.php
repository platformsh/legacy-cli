<?php
namespace Platformsh\Cli\Command\Metrics;

use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\CurlCli;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'metrics:curl', description: "Run an authenticated cURL request on an environment's metrics API")]
class CurlCommand extends MetricsCommandBase
{
    protected $hiddenInList = true;
    public function __construct(private readonly Api $api, private readonly CurlCli $curlCli)
    {
        parent::__construct();
    }

    protected function configure()
    {
        CurlCli::configureInput($this->getDefinition());

        $this->addProjectOption();
        $this->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateInput($input, false, true);

        // Initialize the API service so that it gets CommandBase's event listeners
        // (allowing for auto login).
        $this->api;

        $link = $this->getMetricsLink($this->getSelectedEnvironment());
        if (!$link) {
            return 1;
        }

        $curl = $this->curlCli;

        return $curl->run($link['href'], $input, $output);
    }
}
