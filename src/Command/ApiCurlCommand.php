<?php
namespace Platformsh\Cli\Command;

use Platformsh\Cli\Service\CurlCli;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'api:curl', description: 'Run an authenticated cURL request on the API')]
class ApiCurlCommand extends CommandBase
{
    protected $hiddenInList = true;

    protected function configure()
    {
        CurlCli::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
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
