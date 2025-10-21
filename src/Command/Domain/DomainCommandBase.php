<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Domain;

use Platformsh\Cli\Selector\Selection;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Api;
use Symfony\Contracts\Service\Attribute\Required;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Utils;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\SslUtil;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

abstract class DomainCommandBase extends CommandBase
{
    private Selector $selector;
    private QuestionHelper $questionHelper;
    private Config $config;
    private Api $api;

    // The final array of SSL options for the client parameters.
    /** @var array{certificate?: string, key?: string, chain?: string[]} */
    protected array $sslOptions = [];

    protected ?string $domainName = null;
    protected ?bool $environmentIsProduction = null;
    protected ?string $attach = null;

    #[Required]
    public function autowire(Api $api, Config $config, QuestionHelper $questionHelper, Selector $selector): void
    {
        $this->api = $api;
        $this->config = $config;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
    }

    protected function isForEnvironment(InputInterface $input): bool
    {
        return ($input->hasOption('environment') && $input->getOption('environment') !== null)
            || ($input->hasOption('attach') && $input->getOption('attach') !== null)
            || ($input->hasOption('replace') && $input->getOption('replace') !== null);
    }

    protected function validateDomainInput(InputInterface $input, Selection $selection): bool
    {
        $this->domainName = $input->getArgument('name');
        if (!$this->validDomain($this->domainName)) {
            $this->stdErr->writeln("You must specify a <error>valid domain name</error>");

            return false;
        }

        $certPath = $input->getOption('cert');
        $keyPath = $input->getOption('key');
        $chainPaths = $input->getOption('chain');
        if ($certPath || $keyPath || $chainPaths) {
            if (!isset($certPath, $keyPath)) {
                $this->stdErr->writeln("Both the --cert and the --key are required for SSL certificates");
                return false;
            }
            try {
                $this->sslOptions = (new SslUtil())->validate($certPath, $keyPath, $chainPaths);
            } catch (\InvalidArgumentException $e) {
                $this->stdErr->writeln($e->getMessage());
                return false;
            }
        }

        if ($input->hasOption('environment') || $input->hasOption('attach')) {
            $project = $selection->getProject();
            $supportsNonProduction = $this->supportsNonProductionDomains($project);

            if ($this->isForEnvironment($input)) {
                $environment = $selection->getEnvironment();
                $this->environmentIsProduction = $environment->type === 'production' || $environment->id === $project->default_branch;
                $this->selector->ensurePrintedSelection($selection);
            } elseif ($project->default_branch === null) {
                $this->stdErr->writeln('The <error>default_branch</error> property is not set on the project, so the production environment cannot be determined');
                return false;
            } else {
                $environment = $this->api->getEnvironment($project->default_branch, $project);
                if (!$environment) {
                    $this->stdErr->writeln(sprintf('Environment not found: <error>%s</error>', $project->default_branch));
                    return false;
                }
                $this->environmentIsProduction = true;
                if ($input->hasOption('attach') && $supportsNonProduction) {
                    $this->stdErr->writeln('Use the <comment>--environment</comment> option (and optionally <comment>--attach</comment>) to add a domain to a non-production environment.');
                    $this->stdErr->writeln('');
                }
            }

            if ($input->hasOption('attach')) {
                $this->attach = $input->getOption('attach') ?: $input->getOption('replace');
                if ($this->environmentIsProduction && $this->attach !== null) {
                    $this->stdErr->writeln('The <error>--attach</error> option is only valid for non-production environment domains.');
                    return false;
                }
                if (!$this->environmentIsProduction && !$supportsNonProduction) {
                    $this->stdErr->writeln(sprintf('The project %s does not support non-production environment domains.', $this->api->getProjectLabel($project, 'error')));
                    if ($this->config->has('warnings.non_production_domains_msg')) {
                        $this->stdErr->writeln("\n" . trim($this->config->getStr('warnings.non_production_domains_msg')));
                    }
                    return false;
                }
                if (!$this->environmentIsProduction && $this->attach === null) {
                    $project = $selection->getProject();
                    try {
                        $productionDomains = $project->getDomains();
                        $productionDomainAccess = true;
                    } catch (BadResponseException $e) {
                        if ($e->getResponse()->getStatusCode() === 403) {
                            $productionDomainAccess = false;
                            $productionDomains = [];
                        } else {
                            throw $e;
                        }
                    }
                    if (!$productionDomainAccess) {
                        $this->stdErr->writeln('The <error>--attach</error> option is required for non-production environment domains.');
                        $this->stdErr->writeln("This specifies the production domain that this new domain will replace in the environment's routes.");
                        return false;
                    }
                    if (empty($productionDomains)) {
                        $this->stdErr->writeln('No production domains found.');
                        $this->stdErr->writeln("A domain cannot be added to a non-production environment until the production environment has at least one domain.");
                        return false;
                    }
                    if (count($productionDomains) === 1) {
                        $productionDomain = reset($productionDomains);
                        $this->attach = $productionDomain->name;
                    } else {
                        $choices = [];
                        $default = $project->getProperty('default_domain', false);
                        foreach ($productionDomains as $productionDomain) {
                            if ($productionDomain->name === $default) {
                                $choices[$productionDomain->name] = $productionDomain->name . ' (default)';
                            } else {
                                $choices[$productionDomain->name] = $productionDomain->name;
                            }
                        }
                        $questionText = '<options=bold>Attachment</> (<info>--attach</info>)'
                            . "\nA non-production domain must be attached to an existing production domain."
                            . "\nIt will inherit the same routing behavior."
                            . "\nChoose a production domain:";
                        $this->attach = $this->questionHelper->choose($choices, $questionText, $default);
                    }
                } elseif ($this->attach !== null) {
                    try {
                        $domain = $project->getDomain($this->attach);
                        if ($domain === false) {
                            $this->stdErr->writeln(sprintf(
                                'The production domain (<comment>--attach</comment>) was not found: <error>%s</error>',
                                $this->attach,
                            ));
                            return false;
                        }
                    } catch (BadResponseException $e) {
                        // Ignore access denied errors.
                        if ($e->getResponse()->getStatusCode() !== 403) {
                            throw $e;
                        }
                    }
                }
            }
        }

        return true;
    }

    protected function addDomainOptions(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The domain name')
             ->addOption('cert', null, InputOption::VALUE_REQUIRED, 'The path to a custom certificate file')
             ->addOption('key', null, InputOption::VALUE_REQUIRED, 'The path to the private key for the custom certificate')
             ->addOption('chain', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'The path to the chain file(s) for the custom certificate');
    }

    /**
     * Validates a domain name.
     */
    private function validDomain(string $domain): bool
    {
        return (bool) preg_match('/^([^.]{1,63}\.)+[^.]{2,63}$/', $domain);
    }

    /**
     * Output a clear explanation for domains API errors.
     *
     * @param ClientException $e
     * @param Project         $project
     *
     * @throws ClientException If it can't be explained.
     */
    protected function handleApiException(ClientException $e, Project $project): void
    {
        $response = $e->getResponse();
        if ($response->getStatusCode() === 403) {
            $project->ensureFull();
            $data = $project->getData();
            if (!$project->hasLink('#manage-domains')
                && !empty($data['subscription']['plan'])
                && $data['subscription']['plan'] === 'development') {
                $this->stdErr->writeln('This project is on a Development plan. Upgrade the plan to add domains.');
                return;
            }
        }
        // @todo standardize API error parsing if the format is ever formalized
        if ($response->getStatusCode() === 400) {
            $data = (array) Utils::jsonDecode((string) $response->getBody(), true);
            if (isset($data['detail']['error'])) {
                $this->stdErr->writeln($data['detail']['error']);
                return;
            }
        }
        throw $e;
    }

    /**
     * Checks if a project supports non-production domains.
     */
    protected function supportsNonProductionDomains(Project $project): bool
    {
        $capabilities = $this->api->getProjectCapabilities($project);
        return !empty($capabilities->custom_domains['enabled']);
    }
}
