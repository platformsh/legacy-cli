<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\SshCert\Certifier;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Ssh implements InputConfiguringInterface
{
    protected $input;
    protected $output;
    protected $ssh;
    protected $certifier;

    public function __construct(InputInterface $input, OutputInterface $output, Certifier $certifier)
    {
        $this->input = $input;
        $this->output = $output;
        $this->certifier = $certifier;
    }

    /**
     * @param \Symfony\Component\Console\Input\InputDefinition $definition
     */
    public static function configureInput(InputDefinition $definition)
    {
        $definition->addOption(
            new InputOption('identity-file', 'i', InputOption::VALUE_REQUIRED, 'An SSH identity (private key) to use')
        );
    }

    /**
     * @param array $extraOptions
     *
     * @return array
     */
    public function getSshArgs(array $extraOptions = [])
    {
        $options = array_merge($this->getSshOptions(), $extraOptions);

        $args = [];
        foreach ($options as $name => $value) {
            $args[] = '-o';
            $args[] = $name . ' ' . $value;
        }

        return $args;
    }

    /**
     * Returns an array of SSH options, based on the input options.
     *
     * @return string[] An array of SSH options.
     */
    protected function getSshOptions()
    {
        $options = [];

        $options['SendEnv'] = 'TERM';

        if ($this->input->hasOption('identity-file') && $this->input->getOption('identity-file')) {
            $file = $this->input->getOption('identity-file');
            if (!file_exists($file)) {
                throw new \InvalidArgumentException('Identity file not found: ' . $file);
            }
            $options['IdentitiesOnly'] = 'yes';
            $options['IdentityFile'] = $file;
        } else {
            // Inject the SSH certificate.
            $sshCert = $this->certifier->getExistingCertificate();
            if ($sshCert || $this->certifier->isAutoLoadEnabled()) {
                if (!$sshCert || $sshCert->metadata()->hasExpired()) {
                    $stdErr = $this->output instanceof ConsoleOutputInterface ? $this->output->getErrorOutput() : $this->output;
                    $stdErr->writeln('Generating SSH certificate', OutputInterface::VERBOSITY_VERBOSE);
                    $sshCert = $this->certifier->generateCertificate();
                }

                $options['CertificateFile'] = $sshCert->certificateFilename();
                $options['IdentityFile'] = $sshCert->privateKeyFilename();
            }
        }

        if ($this->output->isDebug()) {
            $options['LogLevel'] = 'DEBUG';
        } elseif ($this->output->isVeryVerbose()) {
            $options['LogLevel'] = 'VERBOSE';
        } elseif ($this->output->isQuiet()) {
            $options['LogLevel'] = 'QUIET';
        }

        return $options;
    }

    /**
     * Returns an SSH command line.
     *
     * @param array $extraOptions
     *
     * @return string
     */
    public function getSshCommand(array $extraOptions = [])
    {
        $command = 'ssh';
        $args = $this->getSshArgs($extraOptions);
        if (!empty($args)) {
            $command .= ' ' . implode(' ', array_map('escapeshellarg', $args));
        }

        return $command;
    }
}
