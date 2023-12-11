<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Service\Url;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WebCommand extends CommandBase
{

    protected function configure()
    {
        $hasConsole = $this->config()->has('service.console_url');
        $this
            ->setName('web')
            ->setDescription($hasConsole ? 'Open the project in the Web Console' : 'Open the project in the Web UI');
        Url::configureInput($this->getDefinition());
        $this->addProjectOption()
             ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
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
            $url = $this->api()->getConsoleURL($project);
            if ($environmentId !== null) {
                // Console links lack the /environments path component.
                $isConsole = ($this->config()->has('service.console_url') && $this->config()->get('api.organizations'))
                    || ($this->config()->has('detection.console_domain') && parse_url($url, PHP_URL_HOST) === $this->config()->get('detection.console_domain'));
                if ($isConsole) {
                    $url .= '/' . rawurlencode($environmentId);
                } else {
                    $url .= '/environments/' . rawurlencode($environmentId);
                }
            }
        } elseif ($this->config()->has('service.console_url')) {
            $url = $this->config()->get('service.console_url');
        } elseif ($this->config()->has('service.accounts_url')) {
            $url = $this->config()->get('service.accounts_url');
        } else {
            $this->stdErr->writeln('No URLs are configured');
            return 1;
        }

        /** @var \Platformsh\Cli\Service\Url $urlService */
        $urlService = $this->getService('url');
        $urlService->openUrl($url);

        return 0;
    }
}
