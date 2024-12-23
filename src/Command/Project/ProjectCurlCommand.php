<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\CurlCli;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'project:curl', description: "Run an authenticated cURL request on a project's API")]
class ProjectCurlCommand extends CommandBase
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
        $this->addCompleter($this->selector);
        $this->addExample('Change the project title', '-X PATCH -d \'{"title": "New title"}\'');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);
        $url = $selection->getProject()->getUri();

        return $this->curlCli->run($url, $input, $output);
    }
}
