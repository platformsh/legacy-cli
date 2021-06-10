<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Service\Url;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WebCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('web')
            ->setDescription('Open the Web UI');
        Url::configureInput($this->getDefinition());
        $this->addProjectOption()
             ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Attempt to select the appropriate project and environment.
        try {
            $this->validateInput($input);
            $environmentId = $this->getSelectedEnvironment()->id;
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
            $subscription = $this->api()->getClient()->getSubscription($this->getSelectedProject()->getSubscriptionId());
            $url = $subscription->project_ui;
            if ($environmentId !== null) {
                // Console links lack the /environments path component.
                if ($this->config()->has('detection.console_domain') && parse_url($url, PHP_URL_HOST) === $this->config()->get('detection.console_domain')) {
                    $url .= '/' . rawurlencode($environmentId);
                } else {
                    $url .= '/environments/' . rawurlencode($environmentId);
                }
            }
        } else {
            $url = $this->config()->getWithDefault('service.console_url', $this->config()->get('service.accounts_url'));
        }

        /** @var \Platformsh\Cli\Service\Url $urlService */
        $urlService = $this->getService('url');
        $urlService->openUrl($url);
    }
}
