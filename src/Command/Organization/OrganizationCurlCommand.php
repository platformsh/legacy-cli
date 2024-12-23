<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Organization;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Config;
use GuzzleHttp\Psr7\Uri;
use Platformsh\Cli\Service\CurlCli;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'organization:curl', description: "Run an authenticated cURL request on an organization's API")]
class OrganizationCurlCommand extends OrganizationCommandBase
{
    protected bool $hiddenInList = true;
    public function __construct(private readonly Config $config, private readonly CurlCli $curlCli, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->selector->addOrganizationOptions($this->getDefinition(), true);
        $this->addCompleter($this->selector);
        CurlCli::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organization = $this->selector->selectOrganization($input);

        $apiUri = new Uri($this->config->getApiUrl());
        $absoluteUrl = $apiUri->withPath($organization->getUri());
        return $this->curlCli->run((string) $absoluteUrl, $input, $output);
    }
}
