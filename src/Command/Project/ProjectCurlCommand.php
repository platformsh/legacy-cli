<?php
namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\CurlCli;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'project:curl', description: "Run an authenticated cURL request on a project's API")]
class ProjectCurlCommand extends CommandBase
{
    protected $hiddenInList = true;
    public function __construct(private readonly Api $api, private readonly CurlCli $curlCli)
    {
        parent::__construct();
    }

    protected function configure()
    {
        CurlCli::configureInput($this->getDefinition());

        $this->addProjectOption();
        $this->addExample('Change the project title', '-X PATCH -d \'{"title": "New title"}\'');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateInput($input);

        // Initialize the API service so that it gets CommandBase's event listeners
        // (allowing for auto login).
        $this->api;

        $url = $this->getSelectedProject()->getUri();

        $curl = $this->curlCli;

        return $curl->run($url, $input, $output);
    }
}
