<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Domain;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Model\EnvironmentDomain;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'domain:delete', description: 'Delete a domain from the project')]
class DomainDeleteCommand extends DomainCommandBase
{
    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Api $api, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The domain name');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->activityMonitor->addWaitOptions($this->getDefinition());
        $this->addExample('Delete the domain example.com', 'example.com');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(envRequired: false));

        $forEnvironment = $input->getOption('environment') !== null;
        $name = $input->getArgument('name');
        $project = $selection->getProject();

        if ($forEnvironment) {
            $httpClient = $this->api->getHttpClient();
            $environment = $selection->getEnvironment();
            $domain = EnvironmentDomain::get($name, $environment->getLink('#domains'), $httpClient);
        } else {
            $domain = $project->getDomain($name);
        }

        if (!$domain) {
            $this->stdErr->writeln("Domain not found: <error>$name</error>");
            return 1;
        }

        // Show a warning about non-production domains when deleting a
        // production one.
        //
        // This is shown regardless of whether any non-production domains exist,
        // because looping through all the non-production environments to fetch
        // their domains would not be scalable.
        $isProductionDomain = $domain->getProperty('type', false) === 'production'
            || (!$forEnvironment || $selection->getEnvironment()->type === 'production');
        if ($isProductionDomain && $this->supportsNonProductionDomains($project)) {
            // Check the project has at least 1 non-inactive, non-production environment.
            $hasNonProductionActiveEnvs = count(array_filter($this->api->getEnvironments($project), fn(Environment $e): bool => $e->type !== 'production' && $e->status !== 'inactive')) > 0;
            if ($hasNonProductionActiveEnvs) {
                $this->stdErr->writeln([
                    '<options=bold>Warning:</>',
                    'If this domain has non-production domains attached to it, they will also be deleted.',
                    'Non-production environments will not be automatically redeployed.',
                    'Consider redeploying these environments so that routes are updated correctly.',
                    '',
                ]);
            }
        }
        if (!$this->questionHelper->confirm("Are you sure you want to delete the domain <info>$name</info>?")) {
            return 1;
        }
        $this->stdErr->writeln('');

        $result = $domain->delete();

        $this->stdErr->writeln("The domain <info>$name</info> has been deleted.");

        if ($this->activityMonitor->shouldWait($input)) {
            $activityMonitor = $this->activityMonitor;
            $activityMonitor->waitMultiple($result->getActivities(), $project);
        }

        return 0;
    }
}
