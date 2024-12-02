<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Url;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'web', description: 'Open the project in the Web Console')]
class WebCommand extends CommandBase
{

    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Url $url)
    {
        parent::__construct();
    }
    protected function configure()
    {
        Url::configureInput($this->getDefinition());
        $this->addProjectOption()
             ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Attempt to select the appropriate project and environment.
        try {
            $this->validateInput($input, true);
            $environmentId = $this->hasSelectedEnvironment() ? $this->getSelectedEnvironment()->id : null;
        } catch (\Exception $e) {
            // If a project has been specified but is not found, then error out.
            if ($input->getOption('project') && !$this->hasSelectedProject()) {
                throw $e;
            }

            // If an environment ID has been specified but not found, then use
            // the specified ID anyway. This allows building a URL when an
            // environment doesn't yet exist.
            $environmentId = $input->getOption('environment');
        }

        if ($this->hasSelectedProject()) {
            $project = $this->getSelectedProject();
            $url = $this->api->getConsoleURL($project);
            if ($environmentId !== null) {
                // Console links lack the /environments path component.
                $isConsole = $this->config->has('detection.console_domain')
                    && parse_url((string) $url, PHP_URL_HOST) === $this->config->get('detection.console_domain');
                if ($isConsole) {
                    $url .= '/' . rawurlencode((string) $environmentId);
                } else {
                    $url .= '/environments/' . rawurlencode((string) $environmentId);
                }
            }
        } elseif ($this->config->has('service.console_url')) {
            $url = $this->config->get('service.console_url');
        } elseif ($this->config->has('service.accounts_url')) {
            $url = $this->config->get('service.accounts_url');
        } else {
            $this->stdErr->writeln('No URLs are configured');
            return 1;
        }

        /** @var Url $urlService */
        $urlService = $this->url;
        $urlService->openUrl($url);

        return 0;
    }
}
