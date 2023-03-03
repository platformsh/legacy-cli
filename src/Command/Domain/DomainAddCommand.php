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
        $this->addOption('replace', 'r', InputOption::VALUE_REQUIRED, 'The production domain which this one replaces (required for non-production environment domains)');
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

        $this->stdErr->writeln(sprintf('Adding the domain <info>%s</info> to the environment %s.', $this->domainName, $this->api()->getEnvironmentLabel($environment)));
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
                            'The environment %s already has a domain with the same <comment>--replace</comment> value: <error>%s</error>',
                            $this->api()->getEnvironmentLabel($environment, 'comment'), $data['detail']['conflicting_domain']
                        ));
                        return 1;
                    }
                    if (isset($data['message'], $data['detail']['prod-domains']) && strpos($data['message'], 'has no corresponding domain set on the production environment') !== false) {
                        $this->stdErr->writeln('');
                        $this->stdErr->writeln(sprintf(
                            'The <comment>--replace</comment> domain does not exist on a production environment: <error>%s</error>',
                            $this->replacementFor
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
