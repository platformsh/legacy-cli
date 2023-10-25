<?php
namespace Platformsh\Cli\Command\Domain;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ClientException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\SslUtil;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

abstract class DomainCommandBase extends CommandBase
{

    // The final array of SSL options for the client parameters.
    protected $sslOptions = [];

    protected $domainName;

    protected $environmentIsProduction;

    protected $attach;

    /**
     * @param InputInterface $input
     *
     * @return bool
     */
    protected function validateDomainInput(InputInterface $input)
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
            $project = $this->getSelectedProject();
            $forEnvironment = ($input->hasOption('environment') && $input->getOption('environment') !== null)
                || ($input->hasOption('attach') && $input->getOption('attach') !== null)
                || ($input->hasOption('replace') && $input->getOption('replace') !== null);

            $supportsNonProduction = $this->supportsNonProductionDomains($project);

            if ($forEnvironment) {
                $this->selectEnvironment($input->getOption('environment'), true, false, true, function (Environment $e) use ($project) {
                    return $e->type !== 'production' && $e->id !== $project->default_branch;
                });
                $environment = $this->getSelectedEnvironment();
                $this->environmentIsProduction = $environment->id === $project->default_branch;
                $this->ensurePrintSelectedEnvironment(true);
            } elseif ($project->default_branch === null) {
                $this->stdErr->writeln('The <error>default_branch</error> property is not set on the project, so the production environment cannot be determined');
                return false;
            } else {
                $this->selectEnvironment($project->default_branch, true, false, false);
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
                    $this->stdErr->writeln(sprintf('The project %s does not support non-production environment domains.', $this->api()->getProjectLabel($project, 'error')));
                    if ($this->config()->has('warnings.non_production_domains_msg')) {
                        $this->stdErr->writeln("\n". trim($this->config()->get('warnings.non_production_domains_msg')));
                    }
                    return false;
                }
                if (!$this->environmentIsProduction && $this->attach === null) {
                    $project = $this->getSelectedProject();
                    try {
                        $productionDomains = $project->getDomains();
                        $productionDomainAccess = true;
                    } catch (BadResponseException $e) {
                        if ($e->getResponse() && $e->getResponse()->getStatusCode() === 403) {
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
                        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
                        $questionHelper = $this->getService('question_helper');
                        $questionText = '<options=bold>Attachment</> (<info>--attach</info>)'
                            . "\nA non-production domain must be attached to an existing production domain."
                            . "\nIt will inherit the same routing behavior."
                            . "\nChoose a production domain:";
                        $this->attach = $questionHelper->choose($choices, $questionText, $default);
                    }
                } elseif ($this->attach !== null) {
                    try {
                        $domain = $project->getDomain($this->attach);
                        if ($domain === false) {
                            $this->stdErr->writeln(sprintf(
                                'The production domain (<comment>--attach</comment>) was not found: <error>%s</error>',
                                $this->attach
                            ));
                            return false;
                        }
                    } catch (BadResponseException $e) {
                        // Ignore access denied errors.
                        if (!$e->getResponse() || $e->getResponse()->getStatusCode() !== 403) {
                            throw $e;
                        }
                    }
                }
            }
        }

        return true;
    }

    protected function addDomainOptions()
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The domain name')
             ->addOption('cert', null, InputOption::VALUE_REQUIRED, 'The path to the certificate file for this domain')
             ->addOption('key', null, InputOption::VALUE_REQUIRED, 'The path to the private key file for the provided certificate.')
             ->addOption('chain', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'The path to the certificate chain file or files for the provided certificate');
    }

    /**
     * Validate a domain.
     *
     * @param string $domain
     *
     * @return bool
     */
    protected function validDomain($domain)
    {
        // @todo: Use symfony/Validator here once it gets the ability to validate just domain.
        return (bool) preg_match('/^([^\.]{1,63}\.)+[^\.]{2,63}$/', $domain);
    }

    /**
     * Output a clear explanation for domains API errors.
     *
     * @param ClientException $e
     * @param Project         $project
     *
     * @throws ClientException If it can't be explained.
     */
    protected function handleApiException(ClientException $e, Project $project)
    {
        $response = $e->getResponse();
        if (!$response) {
            throw $e;
        }
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
            $data = $response->json();
            if (isset($data['detail']['error'])) {
                $this->stdErr->writeln($data['detail']['error']);
                return;
            }
        }
        throw $e;
    }

    /**
     * Checks if a project supports non-production domains.
     *
     * @param Project $project
     *
     * @return bool
     */
    protected function supportsNonProductionDomains(Project $project)
    {
        static $cache = [];
        if (!isset($cache[$project->id])) {
            $capabilities = $project->getCapabilities();
            $cache[$project->id] = !empty($capabilities->custom_domains['enabled']);
        }
        return $cache[$project->id];
    }
}
