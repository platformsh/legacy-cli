<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class DomainAddCommand extends DomainCommand
{

    // The final array of SSL options for the client parameters.
    protected $sslOptions;

    protected $certPath;
    protected $keyPath;
    protected $chainPaths;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('domain:add')
            ->setDescription('Add a new domain to the project.')
            ->addArgument(
                'name',
                InputArgument::OPTIONAL,
                'The name of the domain'
            )
            ->addOption(
                'project',
                null,
                InputOption::VALUE_OPTIONAL,
                'The project ID'
            )
            // @todo: Implement interactive SSL file entry
            // ->addOption('ssl', null, InputOption::VALUE_NONE, 'Specify an SSL certificate chain in interactive mode.')
            ->addOption('cert', null, InputOption::VALUE_REQUIRED, 'The path to the certificate file for this domain.')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'The path to the private key file for the provided certificate.')
            ->addOption('chain', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'The path to the certificate chain file or files for the provided certificate.');
    }

    protected function validateInput(InputInterface $input, OutputInterface $output)
    {
        if (!parent::validateInput($input, $output)) {
            return;
        }
        $this->domainName = $input->getArgument('name');
        if (empty($this->domainName)) {
            $output->writeln("<error>You must specify the name of the domain.</error>");
            return;
        } else if (!$this->validDomain($this->domainName, $output)) {
            $output->writeln("<error>You must specify a valid domain name.</error>");
            return;
        }

        $this->certPath = $input->getOption('cert');
        $this->keyPath = $input->getOption('key');
        $this->chainPaths = $input->getOption('chain');
        if ($this->certPath || $this->keyPath || $this->chainPaths) {
            return $this->validateSslOptions();
        }
        else {
            return TRUE;
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return;
        }

        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion('Is your domain a wildcard? [y/n] ');
        $wildcard = $helper->ask($input, $output, $question);

        // @todo: Ask about SSL uploads if option --ssl is specified instead of inline filenames

        // Assemble our query parameters.
        $domainOpts = array();
        $domainOpts['name'] = $this->domainName;
        $domainOpts['wildcard'] = $wildcard;
        if ($this->sslOptions) {
          $domainOpts['ssl'] = $this->sslOptions;
        }

        // Beam the package up to the mothership.
        $client = $this->getPlatformClient($this->project['endpoint']);
        $client->addDomain($domainOpts);

        // @todo: Add proper exception/error handling here...seriously.
        $message = '<info>';
        $message .= "\nThe given domain has been successfully added to the project. \n";
        $message .= "</info>";
        $output->writeln($message);
    }

    protected function validateSslOptions()
    {
        // Get the contents.
        $sslCertFile = (file_exists($this->certPath) ? trim(file_get_contents($this->certPath)) : '');
        $sslKeyFile = (file_exists($this->keyPath) ? trim(file_get_contents($this->keyPath)) : '');
        $sslChainFiles = $this->assembleChainFiles($this->chainPaths);
        // Do a bit of validation.
        // @todo: Cert first.
        $certResource = openssl_x509_read($sslCertFile);
        if (!$certResource) {
            throw new \Exception("The provided certificate is either not a valid X509 certificate or could not be read.");
        }
        // Then the key. Does it match?
        $keyResource = openssl_pkey_get_private($sslKeyFile);
        if (!$keyResource) {
            throw new \Exception("The provided private key is either not a valid RSA private key or could not be read.");
        }
        $keyMatch = openssl_x509_check_private_key($certResource, $keyResource);
        if (!$keyMatch) {
            throw new \Exception("The provided certificate does not match the provided private key.");
        }
        // Each chain needs to be a valid cert.
        foreach ($sslChainFiles as $chainFile) {
            $chainResource = openssl_x509_read($chainFile);
            if (!$chainResource) {
                throw new \Exception("One of the provided certificates in the chain is not a valid X509 certificate.");
            }
            else {
                openssl_x509_free($chainResource);
            }
        }
        // Yay we win.
        $this->sslOptions = array(
            'certificate' => $sslCertFile,
            'key' => $sslKeyFile,
            'chain' => $sslChainFiles,
        );

        return TRUE;
    }

    protected function assembleChainFiles($chainPaths)
    {
        if (!is_array($chainPaths)) {
            // Skip out if we somehow ended up with crap here.
            return array();
        }
        $chainFiles = array();
        // @todo: We want to split up each cert in the chain, if a file has multiple individual certs.
        // Unfortunately doing this is inconvenient so we'll skip it for now.
        foreach ($chainPaths as $chainPath) {
            $chainFiles[] = (file_exists($chainPath) ? trim(file_get_contents($chainPath)) : '');
        }
        // Yay we're done.
        return $chainFiles;
    }

}
