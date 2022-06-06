<?php
namespace Platformsh\Cli\Command\Organization;

use GuzzleHttp\Psr7\Uri;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\CurlCli;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationCurlCommand extends OrganizationCommandBase
{
    protected static $defaultName = 'organization:curl';
    protected static $defaultDescription = "Run an authenticated cURL request on an organization's API";
    protected $hiddenInList = true;

    private $config;
    private $curlCli;
    private $selector;

    public function __construct(Config $config, CurlCli $curlCli, Selector $selector)
    {
        $this->config = $config;
        $this->curlCli = $curlCli;
        $this->selector = $selector;
        parent::__construct($config);
    }

    protected function configure()
    {
        $this->curlCli->configureInput($this->getDefinition());
        $this->selector->addOrganizationOptions($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organization = $this->selector->selectOrganization($input);

        $apiUrl = new Uri($this->config->get('api.base_url'));
        $absoluteUrl = $apiUrl->withPath((new Uri($organization->getUri()))->getPath());

        return $this->curlCli->run($absoluteUrl, $input, $output);
    }
}
