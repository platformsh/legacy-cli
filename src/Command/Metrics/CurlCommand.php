<?php
namespace Platformsh\Cli\Command\Metrics;

use Platformsh\Cli\Service\CurlCli;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CurlCommand extends MetricsCommandBase
{
    protected $hiddenInList = true;

    protected function configure()
    {
        $this->setName('metrics:curl')
            ->setDescription("Run an authenticated cURL request on an environment's metrics API");

        CurlCli::configureInput($this->getDefinition());

        $this->addProjectOption();
        $this->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateInput($input, false, true);

        // Initialize the API service so that it gets CommandBase's event listeners
        // (allowing for auto login).
        $this->api();

        $link = $this->getMetricsLink($this->getSelectedEnvironment());
        if (!$link) {
            return 1;
        }

        /** @var CurlCli $curl */
        $curl = $this->getService('curl_cli');

        return $curl->run($link['href'], $input, $output);
    }
}
