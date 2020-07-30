<?php
namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\CurlCli;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectCurlCommand extends CommandBase
{
    protected $hiddenInList = true;

    protected function configure()
    {
        $this->setName('project:curl')
            ->setDescription("Run an authenticated cURL request on a project's API");

        CurlCli::configureInput($this->getDefinition());

        $this->addProjectOption();
        $this->addExample('Change the project title', '-X PATCH -d \'{"title": "New title"}\'');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $url = $this->getSelectedProject()->getUri();

        /** @var CurlCli $curl */
        $curl = $this->getService('curl_cli');

        return $curl->run($url, $input, $output);
    }
}
