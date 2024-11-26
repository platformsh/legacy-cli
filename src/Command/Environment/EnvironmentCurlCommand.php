<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\CurlCli;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentCurlCommand extends CommandBase
{
    protected $hiddenInList = true;

    protected function configure()
    {
        $this->setName('environment:curl')
            ->setDescription("Run an authenticated cURL request on an environment's API");

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

        $url = $this->getSelectedEnvironment()->getUri();

        /** @var CurlCli $curl */
        $curl = $this->getService('curl_cli');

        return $curl->run($url, $input, $output);
    }
}
