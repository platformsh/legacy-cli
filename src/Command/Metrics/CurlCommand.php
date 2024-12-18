<?php
namespace Platformsh\Cli\Command\Metrics;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\CurlCli;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'metrics:curl', description: "Run an authenticated cURL request on an environment's metrics API")]
class CurlCommand extends MetricsCommandBase
{
    protected bool $hiddenInList = true;
    public function __construct(private readonly CurlCli $curlCli, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        CurlCli::configureInput($this->getDefinition());

        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(selectDefaultEnv: true));

        $link = $this->getMetricsLink($selection->getEnvironment());
        if (!$link) {
            return 1;
        }

        return $this->curlCli->run($link['href'], $input, $output);
    }
}
