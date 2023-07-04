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
        $this->addOption('attach', null, InputOption::VALUE_REQUIRED, "The production domain that this one replaces in the environment's routes. Required for non-production environment domains.");
        $this->addHiddenOption('replace', 'r', InputOption::VALUE_REQUIRED, 'Deprecated: this has been renamed to --attach');
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
        $this->warnAboutDeprecatedOptions(['replace'], 'The option --replace has been renamed to --attach.');

        $this->validateInput($input, true);

        if (!$this->validateDomainInput($input)) {
            return 1;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        $project = $this->getSelectedProject();
        $environment = $this->getSelectedEnvironment();
        $this->ensurePrintSelectedEnvironment(true);

        $this->stdErr->writeln(sprintf('Adding the domain: <info>%s</info>', $this->domainName));
        if (!empty($this->attach)) {
            $this->stdErr->writeln(sprintf('It will be attached to the production domain: <info>%s</info>', $this->attach));
        }
        $this->stdErr->writeln('');
        if (!$questionHelper->confirm('Are you sure you want to continue?')) {
            return 1;
        }

        $httpClient = $this->api()->getHttpClient();
        try {
            $result = EnvironmentDomain::add($httpClient, $environment, $this->domainName, $this->attach, $this->sslOptions);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            if ($response) {
                $code = $response->getStatusCode();
                if ($code === 402) {
                    $data = $response->json();
                    if (isset($data['message'], $data['detail']['environments_with_domains_limit'], $data['detail']['environments_with_domains'])) {
                        $this->stdErr->writeln('');
                        $this->stdErr->writeln($data['message']);
                        if (!empty($data['detail']['environments_with_domains'])) {
                            $this->stdErr->writeln('Environments with domains: <comment>' . implode('</comment>, <comment>', $data['detail']['environments_with_domains']) . '</comment>');
                        }
                        return 1;
                    }
                }
                if ($code === 409) {
                    $data = $response->json();
                    if (isset($data['message'], $data['detail']['conflicting_domain']) && strpos($data['message'], 'already has a domain with the same replacement_for') !== false) {
                        $this->stdErr->writeln('');
                        $this->stdErr->writeln(sprintf(
                            'The environment %s already has a domain with the same <comment>--attach</comment> value: <error>%s</error>',
                            $this->api()->getEnvironmentLabel($environment, 'comment'), $data['detail']['conflicting_domain']
                        ));
                        return 1;
                    }
                    if (isset($data['message'], $data['detail']['prod-domains']) && strpos($data['message'], 'has no corresponding domain set on the production environment') !== false) {
                        $this->stdErr->writeln('');
                        $this->stdErr->writeln(sprintf(
                            'The <comment>--attach</comment> domain does not exist on a production environment: <error>%s</error>',
                            $this->attach
                        ));
                        if (!empty($data['detail']['prod-domains'])) {
                            $this->stdErr->writeln('');
                            $this->stdErr->writeln("Production environment domains:\n  <comment>" . implode("</comment>\n  <comment>", $data['detail']['prod-domains']) . '</comment>');
                        }
                        return 1;
                    }
                }
            }
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
