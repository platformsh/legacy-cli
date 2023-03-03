<?php
namespace Platformsh\Cli\Command\Domain;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ClientException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\SslUtil;
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

    protected $replacementFor;

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

        if ($input->hasOption('environment') || $input->hasOption('replace')) {
            $project = $this->getSelectedProject();
            $forEnvironment = ($input->hasOption('environment') && $input->getOption('environment') !== null)
                || ($input->hasOption('replace') && $input->getOption('replace') !== null);

            if ($forEnvironment) {
                $this->selectEnvironment($input->getOption('environment'), true, false, true, true);
                $environment = $this->getSelectedEnvironment();
                $this->environmentIsProduction = $environment->id === $project->default_branch;
            } elseif ($project->default_branch === null) {
                $this->stdErr->writeln('The <error>default_branch</error> property is not set on the project, so the production environment cannot be determined');
                return false;
            } else {
                $this->selectEnvironment($project->default_branch, true, false, false);
                $environment = $this->getSelectedEnvironment();
                $this->environmentIsProduction = true;
                $this->stdErr->writeln(sprintf('Selected production environment %s by default', $this->api()->getEnvironmentLabel($environment, 'comment')));
                if ($input->hasOption('replace')) {
                    $this->stdErr->writeln('Use the <comment>--replace</comment> option (and optionally <comment>--environment</comment>) to add a domain to a non-production environment.');
                    $this->stdErr->writeln('');
                }
            }

            if ($input->hasOption('replace')) {
                $this->replacementFor = $input->getOption('replace');
                if (!$this->environmentIsProduction && $this->replacementFor === null) {
                    $this->stdErr->writeln('The <error>--replace</error> option is required for non-production environment domains.');
                    $this->stdErr->writeln('This specifies which production domain the new domain will replace.');
                    return false;
                }
                if ($this->environmentIsProduction && $this->replacementFor !== null) {
                    $this->stdErr->writeln('The <error>--replace</error> option is only valid for non-production environment domains.');
                    return false;
                }
                $capabilities = $project->getCapabilities();
                if (empty($capabilities->custom_domains['enabled']) || empty($capabilities->custom_domains['environments_with_domains_limit'])) {
                    $this->stdErr->writeln(sprintf('The project %s does not support development environment domains.', $this->api()->getProjectLabel($project, 'error')));
                    return false;
                }
                try {
                    $domain = $project->getDomain($this->replacementFor);
                    if ($domain === false) {
                        $this->stdErr->writeln(sprintf(
                            'The <comment>--replace</comment> domain was not found: <error>%s</error>',
                            $this->replacementFor
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
}
