<?php

namespace Platformsh\Cli\Command;

use GuzzleHttp\Exception\BadResponseException;
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
            if ($this->config()->has('service.console_url') && $this->config()->get('api.organizations')) {
                // Load the organization name if possible.
                $firstSegment = $organizationId = $project->getProperty('organization');
                try {
                    $organization = $this->api()->getClient()->getOrganizationById($organizationId);
                    if ($organization) {
                        $firstSegment = $organization->name;
                    }
                } catch (BadResponseException $e) {
                    if ($e->getResponse() && $e->getResponse()->getStatusCode() === 403) {
                        trigger_error($e->getMessage(), E_USER_WARNING);
                    } else {
                        throw $e;
                    }
                }

                $isConsole = true;
                $url = ltrim($this->config()->get('service.console_url'), '/') . '/' . rawurlencode($firstSegment) . '/' . rawurlencode($project->id);
            } else {
                $subscription = $this->api()->getClient()->getSubscription($project->getSubscriptionId());
                $url = $subscription->project_ui;
                $isConsole = $this->config()->has('detection.console_domain') && parse_url($url, PHP_URL_HOST) === $this->config()->get('detection.console_domain');
            }
            if ($environmentId !== null) {
                // Console links lack the /environments path component.
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
