<?php
namespace Platformsh\Cli\Command\Domain;

use GuzzleHttp\Exception\ClientException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

abstract class DomainCommandBase extends CommandBase
{

    // The final array of SSL options for the client parameters.
    protected $sslOptions = [];

    protected $domainName;

    protected $certPath;
    protected $keyPath;
    protected $chainPaths;

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

        $this->certPath = $input->getOption('cert');
        $this->keyPath = $input->getOption('key');
        $this->chainPaths = $input->getOption('chain');
        if ($this->certPath || $this->keyPath || $this->chainPaths) {
            if (!isset($this->certPath, $this->keyPath)) {
                $this->stdErr->writeln("Both the --cert and the --key are required for SSL certificates");
                return false;
            }
            return $this->validateSslOptions();
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
     * @return bool
     */
    protected function validateSslOptions()
    {
        // Get the contents.
        if (!is_readable($this->certPath)) {
            $this->stdErr->writeln("The certificate file could not be read: " . $this->certPath);
            return false;
        }
        $sslCert = trim(file_get_contents($this->certPath));
        // Do a bit of validation.
        $certResource = openssl_x509_read($sslCert);
        if (!$certResource) {
            $this->stdErr->writeln("The certificate file is not a valid X509 certificate: " . $this->certPath);
            return false;
        }
        // Then the key. Does it match?
        if (!is_readable($this->keyPath)) {
            $this->stdErr->writeln("The private key file could not be read: " . $this->keyPath);
            return false;
        }
        $sslPrivateKey = trim(file_get_contents($this->keyPath));
        $keyResource = openssl_pkey_get_private($sslPrivateKey);
        if (!$keyResource) {
            $this->stdErr->writeln("Private key not valid, or passphrase-protected: " . $this->keyPath);
            return false;
        }
        $keyMatch = openssl_x509_check_private_key($certResource, $keyResource);
        if (!$keyMatch) {
            $this->stdErr->writeln("The provided certificate does not match the provided private key.");
            return false;
        }

        // Each chain needs to contain one or more valid certificates.
        $chainFileContents = $this->readChainFiles($this->chainPaths);
        foreach ($chainFileContents as $filePath => $data) {
            $chainResource = openssl_x509_read($data);
            if (!$chainResource) {
                $this->stdErr->writeln("File contains an invalid X509 certificate: " . $filePath);
                return false;
            }
            openssl_x509_free($chainResource);
        }

        // Split up the chain file contents.
        $chain = [];
        $begin = '-----BEGIN CERTIFICATE-----';
        foreach ($chainFileContents as $data) {
            if (substr_count($data, $begin) > 1) {
                foreach (explode($begin, $data) as $cert) {
                    $chain[] = $begin . $cert;
                }
            } else {
                $chain[] = $data;
            }
        }

        // Yay we win.
        $this->sslOptions = [
            'certificate' => $sslCert,
            'key' => $sslPrivateKey,
            'chain' => $chain,
        ];

        return true;
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
     * Get the contents of multiple chain files.
     *
     * @param string[] $chainPaths
     *
     * @throws \Exception If any one of the files is not readable.
     *
     * @return array
     *   An array of file contents (whitespace trimmed) keyed by file name.
     */
    protected function readChainFiles(array $chainPaths)
    {
        $chainFiles = [];
        foreach ($chainPaths as $chainPath) {
            if (!is_readable($chainPath)) {
                throw new \Exception("The chain file could not be read: $chainPath");
            }
            $chainFiles[$chainPath] = trim(file_get_contents($chainPath));
        }

        return $chainFiles;
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
        if ($response !== null && $response->getStatusCode() === 403) {
            $project->ensureFull();
            $data = $project->getData();
            if (!$project->hasLink('#manage-domains')
                && !empty($data['subscription']['plan'])
                && $data['subscription']['plan'] === 'development') {
                $this->stdErr->writeln('This project is on a Development plan. Upgrade the plan to add domains.');
            }
        } else {
            throw $e;
        }
    }
}
