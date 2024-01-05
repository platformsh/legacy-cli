<?php
namespace Platformsh\Cli\Command\Domain;

use Platformsh\Cli\Model\EnvironmentDomain;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DomainDeleteCommand extends DomainCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('domain:delete')
            ->setDescription('Delete a domain from the project')
            ->addArgument('name', InputArgument::REQUIRED, 'The domain name');
        $this->addProjectOption()
            ->addEnvironmentOption()
            ->addWaitOptions();
        $this->addExample('Delete the domain example.com', 'example.com');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, true);

        $forEnvironment = $input->getOption('environment') !== null;
        $name = $input->getArgument('name');
        $project = $this->getSelectedProject();

        if ($forEnvironment) {
            $httpClient = $this->api()->getHttpClient();
            $environment = $this->getSelectedEnvironment();
            $domain = EnvironmentDomain::get($name, $environment->getLink('#domains'), $httpClient);
        }
        else {
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
            || (!$forEnvironment || $this->getSelectedEnvironment()->type === 'production');
        if ($isProductionDomain && $this->supportsNonProductionDomains($project)) {
            // Check the project has at least 1 non-inactive, non-production environment.
            $hasNonProductionActiveEnvs = count(array_filter($this->api()->getEnvironments($project), function (Environment $e) {
                return $e->type !== 'production' && $e->status !== 'inactive';
            })) > 0;
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

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if (!$questionHelper->confirm("Are you sure you want to delete the domain <info>$name</info>?")) {
            return 1;
        }
        $this->stdErr->writeln('');

        $result = $domain->delete();

        $this->stdErr->writeln("The domain <info>$name</info> has been deleted.");

        if ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $activityMonitor->waitMultiple($result->getActivities(), $project);
        }

        return 0;
    }
}
