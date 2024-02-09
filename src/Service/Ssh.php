<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Console\HiddenInputOption;
use Platformsh\Cli\SshCert\Certifier;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Ssh implements InputConfiguringInterface
{
    const SSH_NO_REFRESH_ENV_VAR = 'CLI_SSH_NO_REFRESH';

    protected $input;
    protected $output;
    protected $stdErr;
    protected $config;
    protected $certifier;
    protected $sshConfig;
    protected $sshKey;

    public function __construct(InputInterface $input, OutputInterface $output, Config $config, Certifier $certifier, SshConfig $sshConfig, SshKey $sshKey)
    {
        $this->input = $input;
        $this->output = $output;
        $this->config = $config;
        $this->sshKey = $sshKey;
        $this->certifier = $certifier;
        $this->sshConfig = $sshConfig;
        $this->stdErr = $this->output instanceof ConsoleOutputInterface ? $this->output->getErrorOutput() : $this->output;
    }

    /**
     * @param \Symfony\Component\Console\Input\InputDefinition $definition
     */
    public static function configureInput(InputDefinition $definition)
    {
        $definition->addOption(
            new HiddenInputOption('identity-file', 'i', InputOption::VALUE_REQUIRED, 'Deprecated: an SSH identity (private key) to use. The auto-generated certificate is recommended instead.')
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
            if ($this->config->get('api.debug')) {
                $options[] = 'LogLevel DEBUG3';
            } else {
                $options[] = 'LogLevel DEBUG';
            }
        } elseif ($this->output->isVeryVerbose()) {
            $options[] = 'LogLevel VERBOSE';
        } elseif ($this->output->isQuiet()) {
            $options[] = 'LogLevel QUIET';
        }

        $hasIdentity = false;
        if ($this->input->hasOption('identity-file') && ($file = $this->input->getOption('identity-file'))) {
            $options[] = 'IdentityFile ' . $this->sshConfig->formatFilePath($file);
            $options[] = 'IdentitiesOnly yes';
            $hasIdentity = true;
        } else {
            // Inject the SSH certificate.
            $sshCert = $this->certifier->getExistingCertificate();
            if ($sshCert || $this->certifier->isAutoLoadEnabled()) {
                if ((!$sshCert || !$this->certifier->isValid($sshCert)) && $this->sshConfig->checkRequiredVersion()) {
                    $this->stdErr->writeln('Generating SSH certificate...', OutputInterface::VERBOSITY_VERBOSE);
                    try {
                        $sshCert = $this->certifier->generateCertificate($sshCert);
                        $this->stdErr->writeln("A new SSH certificate has been generated.\n", OutputInterface::VERBOSITY_VERBOSE);
                    } catch (\Exception $e) {
                        $this->stdErr->writeln(sprintf("Failed to generate SSH certificate: <error>%s</error>\n", $e->getMessage()));
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
                    $hasIdentity = true;
                }
            }
        }

        if (!$hasIdentity && ($sessionIdentityFile = $this->sshKey->selectIdentity())) {
            $options[] = 'IdentityFile ' . $this->sshConfig->formatFilePath($sessionIdentityFile);
        }

        // Configure host keys and link them.
        try {
            $keysFile = $this->sshConfig->configureHostKeys();
            if ($keysFile !== null) {
                $options[] = 'UserKnownHostsFile ~/.ssh/known_hosts ~/.ssh/known_hosts2 ' . $this->sshConfig->formatFilePath($keysFile);
            }
        } catch (\Exception $e) {
            $this->stdErr->writeln('Error configuring host keys: ' . $e->getMessage(), OutputInterface::VERBOSITY_VERBOSE);
        }

        if ($configuredOptions = $this->config->get('ssh.options')) {
            $options = array_merge($options, is_array($configuredOptions) ? $configuredOptions : explode("\n", $configuredOptions));
        }

        return $options;
    }

    /**
     * Returns an SSH command line.
     *
     * @param string[] $extraOptions
     * @param string|null $uri
     * @param string|null $remoteCommand
     * @param bool $autoConfigure
     *
     * @return string
     */
    public function getSshCommand(array $extraOptions = [], $uri = null, $remoteCommand = null, $autoConfigure = true)
    {
        $command = 'ssh';
        $args = $this->getSshArgs($extraOptions, $uri, $remoteCommand);
        if (!empty($args)) {
            $command .= ' ' . implode(' ', array_map([OsUtil::class, 'escapeShellArg'], $args));
        }

        // Configure or validate the session SSH config.
        if ($autoConfigure) {
            try {
                $this->sshConfig->configureSessionSsh();
            } catch (\Exception $e) {
                $this->stdErr->writeln('Error configuring SSH: ' . $e->getMessage(), OutputInterface::VERBOSITY_VERBOSE);
            }
        }

        return $command;
    }

    /**
     * Returns environment variables to set on SSH commands.
     *
     * @return array<string, string>
     */
    public function getEnv()
    {
        // Suppress refreshing the certificate while SSH is running through the CLI.
        return [self::SSH_NO_REFRESH_ENV_VAR => '1'];
    }
}
