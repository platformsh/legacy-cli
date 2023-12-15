<?php
namespace Platformsh\Cli\Command;

use Platformsh\Cli\Service\CurlCli;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ApiCurlCommand extends CommandBase
{
    protected $hiddenInList = true;

    protected function configure()
    {
        $this->setName('api:curl')
            ->setDescription(sprintf('Run an authenticated cURL request on the %s API', $this->config()->get('service.name')));

        CurlCli::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $url = $this->config()->getApiUrl();

        // Initialize the API service so that it gets CommandBase's event listeners
        // (allowing for auto login).
        $this->api();

        /** @var CurlCli $curl */
        $curl = $this->getService('curl_cli');

        return $curl->run($url, $input, $output);
    }
}
