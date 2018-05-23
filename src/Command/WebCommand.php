<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Url;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WebCommand extends CommandBase
{
    protected static $defaultName = 'web';

    private $config;
    private $selector;
    private $urlService;

    public function __construct(
        Config $config,
        Selector $selector,
        Url $urlService
    ) {
        $this->config = $config;
        $this->selector = $selector;
        $this->urlService = $urlService;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Open the Web UI');

        $definition = $this->getDefinition();
        $this->urlService->configureInput($definition);
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Attempt to select the appropriate project and environment.
        try {
            $selection = $this->selector->getSelection($input);
            $environmentId = $selection->getEnvironment()->id;
        } catch (\Exception $e) {
            // If a project has been specified but is not found, then error out.
            if ($input->getOption('project')) {
                throw $e;
            }

            // If an environment ID has been specified but not found, then use
            // the specified ID anyway. This allows building a URL when an
            // environment doesn't yet exist.
            $environmentId = $input->getOption('environment');
        }

        $url = $selection->getProject()->getLink('#ui');
        if ($selection->hasEnvironment()) {
            $url .= '/environments/' . rawurlencode($environmentId);
        }

        $this->urlService->openUrl($url);
    }
}
