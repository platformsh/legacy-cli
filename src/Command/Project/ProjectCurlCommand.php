<?php
namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\CurlCli;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'project:curl', description: "Run an authenticated cURL request on a project's API")]
class ProjectCurlCommand extends CommandBase
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
        $this->addExample('Change the project title', '-X PATCH -d \'{"title": "New title"}\'');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);

        // Initialize the API service so that it gets CommandBase's event listeners
        // (allowing for auto login).
        $this->api;

        $url = $selection->getProject()->getUri();

        $curl = $this->curlCli;

        return $curl->run($url, $input, $output);
    }
}
