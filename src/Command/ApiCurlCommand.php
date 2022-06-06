<?php
namespace Platformsh\Cli\Command;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\CurlCli;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ApiCurlCommand extends CommandBase
{
    protected static $defaultName = 'api:curl';
    protected static $defaultDescription = 'Run an authenticated cURL request on the API';
    protected $hiddenInList = true;

    private $config;
    private $curlCli;

    public function __construct(Config $config, CurlCli $curlCli)
    {
        $this->config = $config;
        $this->curlCli = $curlCli;
        parent::__construct();
    }

    public function isEnabled()
    {
        return $this->config->has('api.base_url') && parent::isEnabled();
    }

    protected function configure()
    {
        $this->curlCli->configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $url = $this->config->get('api.base_url');
        return $this->curlCli->run($url, $input, $output);
    }
}
