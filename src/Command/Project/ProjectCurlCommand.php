<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\CurlCli;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectCurlCommand extends CommandBase
{
    protected static $defaultName = 'project:curl';
    protected static $defaultDescription = "Run a cURL request on a project's API";

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

        $this->addExample('Change the project title', '-X PATCH -d \'{"title": "New title"}\'');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->selector->getSelection($input)->getProject();
        $url = $project->getUri();
        return $this->curl->run($url, $input, $output);
    }
}
