<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\CurlCli;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'environment:curl', description: "Run an authenticated cURL request on an environment's API")]
class EnvironmentCurlCommand extends CommandBase
{
    protected bool $hiddenInList = true;
    public function __construct(private readonly Api $api, private readonly CurlCli $curlCli, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure()
    {
        CurlCli::configureInput($this->getDefinition());

        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(selectDefaultEnv: true));

        // Initialize the API service so that it gets CommandBase's event listeners
        // (allowing for auto login).
        $this->api;

        $url = $selection->getEnvironment()->getUri();

        $curl = $this->curlCli;

        return $curl->run($url, $input, $output);
    }
}
