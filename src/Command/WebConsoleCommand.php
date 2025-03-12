<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Selector\Selection;
use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Url;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'console', description: 'Open the project in the Console', aliases: ['web'])]
class WebConsoleCommand extends CommandBase
{
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Selector $selector, private readonly Url $url)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        Url::configureInput($this->getDefinition());
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Attempt to select the appropriate project and environment.
        try {
            $selection = $this->selector->getSelection($input, new SelectorConfig(envRequired: false));
            $environmentId = $selection->hasEnvironment() ? $selection->getEnvironment()->id : null;
        } catch (\Exception $e) {
            // If a project has been specified but is not found, then error out.
            if ($input->getOption('project')) {
                throw $e;
            }
            $selection = new Selection();

            // If an environment ID has been specified but not found, then use
            // the specified ID anyway. This allows building a URL when an
            // environment doesn't yet exist.
            $environmentId = $input->getOption('environment');
        }

        if ($selection->hasProject()) {
            $project = $selection->getProject();
            $url = $this->api->getConsoleURL($project);
            if ($environmentId !== null) {
                // Console links lack the /environments path component.
                $isConsole = $this->config->has('detection.console_domain')
                    && parse_url($url, PHP_URL_HOST) === $this->config->getStr('detection.console_domain');
                if ($isConsole) {
                    $url .= '/' . rawurlencode((string) $environmentId);
                } else {
                    $url .= '/environments/' . rawurlencode((string) $environmentId);
                }
            }
        } elseif ($this->config->has('service.console_url')) {
            $url = $this->config->getStr('service.console_url');
        } elseif ($this->config->has('service.accounts_url')) {
            $url = $this->config->getStr('service.accounts_url');
        } else {
            $this->stdErr->writeln('No URLs are configured');
            return 1;
        }
        $this->url->openUrl($url);

        return 0;
    }
}
