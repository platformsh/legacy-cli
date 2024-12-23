<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Domain;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Model\EnvironmentDomain;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'domain:update', description: 'Update a domain')]
class DomainUpdateCommand extends DomainCommandBase
{
    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Api $api, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addDomainOptions();
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->activityMonitor->addWaitOptions($this->getDefinition());
        $this->addExample(
            'Update the custom certificate for the domain example.org',
            'example.org --cert example-org.crt --key example-org.key',
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selectorConfig = new SelectorConfig(envRequired: false);
        if ($this->isForEnvironment($input)) {
            $selectorConfig = new SelectorConfig(
                chooseEnvFilter: fn(Environment $e): bool => $e->type !== 'production',
            );
        }
        $selection = $this->selector->getSelection($input, $selectorConfig);

        if (!$this->validateDomainInput($input, $selection)) {
            return 1;
        }

        $forEnvironment = $input->getOption('environment') !== null;
        $environment = $forEnvironment ? $selection->getEnvironment() : null;

        $project = $selection->getProject();

        if ($forEnvironment) {
            $httpClient = $this->api->getHttpClient();
            $domain = EnvironmentDomain::get($this->domainName, $environment->getLink('#domains'), $httpClient);
        } else {
            $domain = $project->getDomain($this->domainName);
        }
        if (!$domain) {
            $this->stdErr->writeln('Domain not found: <error>' . $this->domainName . '</error>');
            return 1;
        }

        $needsUpdate = false;
        foreach (['key' => '', 'certificate' => '', 'chain' => []] as $option => $default) {
            if (empty($this->sslOptions[$option])) {
                $this->sslOptions[$option] = $domain->ssl[$option] ?: $default;
            } elseif ($this->sslOptions[$option] != $domain->ssl[$option]) {
                $needsUpdate = true;
            }
        }
        if (!$needsUpdate) {
            $this->stdErr->writeln('There is nothing to update.');

            return 0;
        }

        $this->stdErr->writeln('Updating the domain <info>' . $this->domainName . '</info>');

        $result = $domain->update(['ssl' => $this->sslOptions]);

        if ($this->activityMonitor->shouldWait($input)) {
            $activityMonitor = $this->activityMonitor;
            $activityMonitor->waitMultiple($result->getActivities(), $project);
        }

        return 0;
    }
}
