<?php
namespace Platformsh\Cli\Command\Domain;

use GuzzleHttp\Exception\ClientException;
use Platformsh\Cli\Model\EnvironmentDomain;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DomainAddCommand extends DomainCommandBase
{

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('domain:add')
            ->setDescription('Add a new domain to the project');
        $this->addDomainOptions();
        $this->addOption('replace', null, InputOption::VALUE_REQUIRED, 'The production domain which this one replaces (required for non-production environment domains)');
        $this->addProjectOption()
            ->addEnvironmentOption()
            ->addWaitOptions();
        $this->addExample('Add the domain example.com', 'example.com');
        $this->addExample(
            'Add the domain secure.example.com with SSL enabled',
            'secure.example.com --cert secure-example-com.crt --key secure-example-com.key'
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

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        $project = $this->getSelectedProject();
        $environment = $this->getSelectedEnvironment();

        $this->stdErr->writeln(sprintf('Adding the domain <info>%s</info> to the environment %s', $this->domainName, $this->api()->getEnvironmentLabel($environment)));
        if (!empty($this->replacementFor)) {
            $this->stdErr->writeln(sprintf('The domain will replace the production domain <info>%s</info>', $this->replacementFor));
        }
        $this->stdErr->writeln('');
        if (!$questionHelper->confirm('Are you sure you want to continue?')) {
            return 1;
        }

        $httpClient = $this->api()->getHttpClient();
        try {
            $result = EnvironmentDomain::add($httpClient, $environment, $this->domainName, $this->replacementFor, $this->sslOptions);
        } catch (ClientException $e) {
            $this->handleApiException($e, $project);

            return 1;
        }

        if ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $activityMonitor->waitMultiple($result->getActivities(), $project);
        }

        return 0;
    }
}
