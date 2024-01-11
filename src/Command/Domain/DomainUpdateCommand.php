<?php
namespace Platformsh\Cli\Command\Domain;

use Platformsh\Cli\Model\EnvironmentDomain;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DomainUpdateCommand extends DomainCommandBase
{

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('domain:update')
            ->setDescription('Update a domain');
        $this->addDomainOptions();
        $this->addProjectOption()
            ->addEnvironmentOption()
            ->addWaitOptions();
        $this->addExample(
            'Update the custom certificate for the domain example.org',
            'example.org --cert example-org.crt --key example-org.key'
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, true);

        if (!$this->validateDomainInput($input)) {
            return 1;
        }

        $forEnvironment = $input->getOption('environment') !== null;
        $environment = $forEnvironment ? $this->getSelectedEnvironment() : null;

        $project = $this->getSelectedProject();

        if ($forEnvironment) {
            $httpClient = $this->api()->getHttpClient();
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

        if ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $activityMonitor->waitMultiple($result->getActivities(), $project);
        }

        return 0;
    }
}
