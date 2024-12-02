<?php
namespace Platformsh\Cli\Command\Domain;

use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Utils;
use Platformsh\Cli\Model\EnvironmentDomain;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'domain:add', description: 'Add a new domain to the project')]
class DomainAddCommand extends DomainCommandBase
{

    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Api $api, private readonly QuestionHelper $questionHelper)
    {
        parent::__construct();
    }
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addDomainOptions();
        $this->addOption('attach', null, InputOption::VALUE_REQUIRED, "The production domain that this one replaces in the environment's routes. Required for non-production environment domains.");
        $this->addHiddenOption('replace', 'r', InputOption::VALUE_REQUIRED, 'Deprecated: this has been renamed to --attach');
        $this->addProjectOption()
            ->addEnvironmentOption()
            ->addWaitOptions();
        $this->addExample('Add the domain example.com', 'example.com');
        $this->addExample(
            'Add the domain example.org with a custom SSL/TLS certificate',
            'example.org --cert example-org.crt --key example-org.key'
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->warnAboutDeprecatedOptions(['replace'], 'The option --replace has been renamed to --attach.');

        $this->validateInput($input, true);

        if (!$this->validateDomainInput($input)) {
            return 1;
        }

        /** @var QuestionHelper $questionHelper */
        $questionHelper = $this->questionHelper;

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

        $httpClient = $this->api->getHttpClient();
        try {
            $result = EnvironmentDomain::add($httpClient, $environment, $this->domainName, $this->attach, $this->sslOptions);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            if ($response) {
                $code = $response->getStatusCode();
                if ($code === 402) {
                    $data = Utils::jsonDecode((string) $response->getBody(), true);
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
                    $data = Utils::jsonDecode((string) $response->getBody(), true);
                    if (isset($data['message'], $data['detail']['conflicting_domain']) && str_contains((string) $data['message'], 'already has a domain with the same replacement_for')) {
                        $this->stdErr->writeln('');
                        $this->stdErr->writeln(sprintf(
                            'The environment %s already has a domain with the same <comment>--attach</comment> value: <error>%s</error>',
                            $this->api->getEnvironmentLabel($environment, 'comment'), $data['detail']['conflicting_domain']
                        ));
                        return 1;
                    }
                    if (isset($data['message'], $data['detail']['prod-domains']) && str_contains((string) $data['message'], 'has no corresponding domain set on the production environment')) {
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
            /** @var ActivityMonitor $activityMonitor */
            $activityMonitor = $this->activityMonitor;
            $activityMonitor->waitMultiple($result->getActivities(), $project);
        }

        return 0;
    }
}
