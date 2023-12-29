<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\SshCert\Certifier;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Ssh implements InputConfiguringInterface
{
    protected $input;
    protected $output;
    protected $certifier;
    protected $sshConfig;
    protected $sshKey;

    public function __construct(InputInterface $input, OutputInterface $output, Certifier $certifier, SshConfig $sshConfig, SshKey $sshKey)
    {
        $this->input = $input;
        $this->output = $output;
        $this->sshKey = $sshKey;
        $this->certifier = $certifier;
        $this->sshConfig = $sshConfig;
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
     * Returns arguments for an SSH command.
     *
     * @param string[] $extraOptions
     * @param string|null $uri
     * @param string[]|string|null $remoteCommand
     *
     * @return array
     */
    public function getSshArgs(array $extraOptions = [], $uri = null, $remoteCommand = null)
    {
        $options = array_merge($this->getSshOptions(), $extraOptions);

        $args = [];
        foreach ($options as $option) {
            $args[] = '-o';
            $args[] = $option;
        }

        if ($uri !== null) {
            $args[] = $uri;
        }
        if (!empty($remoteCommand)) {
            // The remote command may be provided as 1 argument (escaped
            // according to the user), or as multiple arguments, in which case
            // it will be collapsed into a string escaped for the remote POSIX
            // shell.
            if (is_array($remoteCommand)) {
                if (count($remoteCommand) === 1) {
                    $args[] = reset($remoteCommand);
                } else {
                    $args[] = implode(' ', array_map([OsUtil::class, 'escapePosixShellArg'], $remoteCommand));
                }
            } else {
                $args[] = $remoteCommand;
            }
        }

        return $args;
    }

    /**
     * Returns an array of SSH options, based on the input options.
     *
     * @return string[] An array of SSH options.
     */
    private function getSshOptions()
    {
        $options = [];

        $options[] = 'SendEnv TERM';

        if ($this->output->isDebug()) {
            $options[] = 'LogLevel DEBUG';
        } elseif ($this->output->isVeryVerbose()) {
            $options[] = 'LogLevel VERBOSE';
        } elseif ($this->output->isQuiet()) {
            $options[] = 'LogLevel QUIET';
        }

        if ($this->input->hasOption('identity-file') && $this->input->getOption('identity-file')) {
            $file = $this->input->getOption('identity-file');
            if (!file_exists($file)) {
                throw new \InvalidArgumentException('Identity file not found: ' . $file);
            }
            $options[] = 'IdentitiesOnly yes';
            $options[] = 'IdentityFile ' . $this->sshConfig->formatFilePath($file);
        } else {
            // Inject the SSH certificate.
            $sshCert = $this->certifier->getExistingCertificate();
            if ($sshCert || $this->certifier->isAutoLoadEnabled()) {
                $stdErr = $this->output instanceof ConsoleOutputInterface ? $this->output->getErrorOutput() : $this->output;

                if ((!$sshCert || !$this->certifier->isValid($sshCert)) && $this->sshConfig->checkRequiredVersion()) {
                    $stdErr->writeln('Generating SSH certificate...', OutputInterface::VERBOSITY_VERBOSE);
                    try {
                        $sshCert = $this->certifier->generateCertificate();
                        $stdErr->writeln("A new SSH certificate has been generated.\n", OutputInterface::VERBOSITY_VERBOSE);
                    } catch (\Exception $e) {
                        $stdErr->writeln(sprintf("Failed to generate SSH certificate: <error>%s</error>\n", $e->getMessage()));
                    }
                }

                if ($sshCert) {
                    if ($this->sshConfig->supportsCertificateFile()) {
                        $options[] = 'CertificateFile ' . $this->sshConfig->formatFilePath($sshCert->certificateFilename());
                    }
                    $options[] = 'IdentityFile ' . $this->sshConfig->formatFilePath($sshCert->privateKeyFilename());
                    if ($this->certifier->useCertificateOnly()) {
                        $options[] = 'IdentitiesOnly yes';
                    }
                }
            }
        }

        if (empty($options['IdentitiesOnly']) && ($sessionIdentityFile = $this->sshKey->selectIdentity())) {
            $options[] = 'IdentityFile ' . $this->sshConfig->formatFilePath($sessionIdentityFile);
        }

        // Configure host keys and link them.
        if (($keysFile = $this->sshConfig->configureHostKeys()) !== null) {
            $options[] = 'UserKnownHostsFile ~/.ssh/known_hosts ~/.ssh/known_hosts2 ' . $this->sshConfig->formatFilePath($keysFile);
        }

        // Configure or validate the session SSH config.
        $this->sshConfig->configureSessionSsh();

        return $options;
    }

    /**
     * Returns an SSH command line.
     *
     * @param string[] $extraOptions
     * @param string|null $uri
     * @param string|null $remoteCommand
     *
     * @return string
     */
    public function getSshCommand(array $extraOptions = [], $uri = null, $remoteCommand = null)
    {
        $command = 'ssh';
        $args = $this->getSshArgs($extraOptions, $uri, $remoteCommand);
        if (!empty($args)) {
            $command .= ' ' . implode(' ', array_map([OsUtil::class, 'escapeShellArg'], $args));
        }

        return $command;
    }
}
