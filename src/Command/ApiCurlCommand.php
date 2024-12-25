<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\CurlCli;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'api:curl', description: 'Run an authenticated cURL request on the API')]
class ApiCurlCommand extends CommandBase
{
    protected bool $hiddenInList = true;

    public function __construct(private readonly Config $config, private readonly CurlCli $curlCli)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        CurlCli::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->curlCli->run($this->config->getApiUrl(), $input, $output);
    }
}
