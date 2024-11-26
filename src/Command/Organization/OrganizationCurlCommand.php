<?php
namespace Platformsh\Cli\Command\Organization;

use GuzzleHttp\Psr7\Uri;
use Platformsh\Cli\Service\CurlCli;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'organization:curl', description: "Run an authenticated cURL request on an organization's API")]
class OrganizationCurlCommand extends OrganizationCommandBase
{
    protected $hiddenInList = true;

    protected function configure()
    {
        $this
            ->addOrganizationOptions(true);
        CurlCli::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organization = $this->validateOrganizationInput($input);

        $apiUri = new Uri($this->config()->getApiUrl());
        $absoluteUrl = $apiUri->withPath($organization->getUri());

        /** @var CurlCli $curl */
        $curl = $this->getService('curl_cli');
        return $curl->run($absoluteUrl, $input, $output);
    }
}
