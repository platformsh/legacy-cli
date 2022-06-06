<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\CurlCli;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentCurlCommand extends CommandBase
{
    protected static $defaultName = 'environment:curl';
    protected static $defaultDescription = "Run a cURL request on an environment's API";

    protected $hiddenInList = true;

    private $curl;
    private $selector;

    public function __construct(CurlCli $curlCli, Selector $selector) {
        $this->curl = $curlCli;
        $this->selector = $selector;
        parent::__construct();
    }

    protected function configure()
    {
        $this->curl->configureInput($this->getDefinition());
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);
        $url = $selection->getEnvironment()->getUri();

        return $this->curl->run($url, $input, $output);
    }
}
