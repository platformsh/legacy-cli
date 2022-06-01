<?php
namespace Platformsh\Cli\Command\Organization;

use GuzzleHttp\Url;
use Platformsh\Cli\Service\CurlCli;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationCurlCommand extends OrganizationCommandBase
{
    protected $hiddenInList = true;

    protected function configure()
    {
        $this->setName('organization:curl')
            ->setDescription("Run an authenticated cURL request on an organization's API")
            ->addOrganizationOptions();
        CurlCli::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $organization = $this->validateOrganizationInput($input);

        $apiUrl = Url::fromString($this->config()->get('api.base_url'));
        $absoluteUrl = $apiUrl->combine($organization->getUri())->__toString();

        /** @var CurlCli $curl */
        $curl = $this->getService('curl_cli');
        return $curl->run($absoluteUrl, $input, $output);
    }
}
