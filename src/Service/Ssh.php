<?php

declare(strict_types=1);

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
    public const SSH_NO_REFRESH_ENV_VAR = 'CLI_SSH_NO_REFRESH';
    protected OutputInterface $stdErr;

    private bool $configuredSession = false;

    public function __construct(protected InputInterface $input, protected OutputInterface $output, protected Config $config, protected Certifier $certifier, protected SshConfig $sshConfig, protected SshKey $sshKey)
    {
        $this->stdErr = $this->output instanceof ConsoleOutputInterface ? $this->output->getErrorOutput() : $this->output;
    }

    /**
     * @param InputDefinition $definition
     */
    public static function configureInput(InputDefinition $definition): void
    {
        $definition->addOption(
            new HiddenInputOption('identity-file', 'i', InputOption::VALUE_REQUIRED, 'Deprecated: an SSH identity (private key) to use. The auto-generated certificate is recommended instead.'),
        );
    }

    /**
     * Returns arguments for an SSH command.
     *
     * @param string $uri
     *   The SSH URI. This is required for detecting whether authentication
     *   should be added. It will not be returned as one of the arguments.
     * @param string[] $extraOptions
     *   Extra SSH options in the OpenSSH config format, e.g. 'RequestTTY yes'.
     * @param string[]|string|null $remoteCommand
     *   A command to run on the remote host.
     *
     * @return string[]
     */
    public function getSshArgs(string $uri, array $extraOptions = [], array|string|null $remoteCommand = null): array
    {
        $options = array_merge($this->getSshOptions($this->hostIsInternal($uri)), $extraOptions);

        $args = [];
        foreach ($options as $option) {
            $args[] = '-o';
            $args[] = $option;
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
                    $args[] = implode(' ', array_map(OsUtil::escapePosixShellArg(...), $remoteCommand));
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
     * @param bool|null $hostIsInternal
     *
     * @return string[] An array of SSH options.
     */
    private function getSshOptions(?bool $hostIsInternal): array
    {
        $options = [];

        $options[] = 'SendEnv TERM';

        if ($this->output->isDebug()) {
            if ($this->config->getBool('api.debug')) {
                $options[] = 'LogLevel DEBUG3';
            } else {
                $options[] = 'LogLevel DEBUG';
            }
        } elseif ($this->output->isVeryVerbose()) {
            $options[] = 'LogLevel VERBOSE';
        } elseif ($this->output->isQuiet()) {
            $options[] = 'LogLevel QUIET';
        }

        if ($this->input->hasOption('identity-file') && ($file = $this->input->getOption('identity-file'))) {
            foreach ($this->sshConfig->formattedPaths($file) as $path) {
                $options[] = 'IdentityFile ' . $path;
            }
            $options[] = 'IdentitiesOnly yes';
        } elseif ($hostIsInternal !== false) {
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
                        foreach ($this->sshConfig->formattedPaths($sshCert->certificateFilename()) as $path) {
                            $options[] = 'CertificateFile ' . $path;
                        }
                    }
                    foreach ($this->sshConfig->formattedPaths($sshCert->privateKeyFilename()) as $path) {
                        $options[] = 'IdentityFile ' . $path;
                    }
                    if ($hostIsInternal) {
                        $options[] = 'IdentitiesOnly yes';
                    }
                }
            }
            if (!$sshCert && ($sessionIdentityFile = $this->sshKey->selectIdentity())) {
                foreach ($this->sshConfig->formattedPaths($sessionIdentityFile) as $path) {
                    $options[] = 'IdentityFile ' . $path;
                }
                if ($hostIsInternal) {
                    $options[] = 'IdentitiesOnly yes';
                }
            }
        }

        // Configure host keys and link them.
        if ($hostIsInternal !== false) {
            try {
                $keysFile = $this->sshConfig->configureHostKeys();
                if ($keysFile !== null) {
                    $options[] = 'UserKnownHostsFile ~/.ssh/known_hosts ~/.ssh/known_hosts2 ' . implode(' ', $this->sshConfig->formattedPaths($keysFile));
                }
            } catch (\Exception $e) {
                $this->stdErr->writeln('Error configuring host keys: ' . $e->getMessage(), OutputInterface::VERBOSITY_VERBOSE);
            }
        }

        if ($configuredOptions = $this->config->get('ssh.options')) {
            $options = array_merge($options, is_array($configuredOptions) ? $configuredOptions : explode("\n", (string) $configuredOptions));
        }

        // Avoid repeating options.
        return array_unique($options);
    }

    /**
     * Returns an SSH command line.
     *
     * @param string $url
     *   The SSH URL. Use $omitUrl to control whether this should be added to
     *   the command line.
     * @param string[] $extraOptions
     *   SSH options, e.g. 'RequestTTY yes'.
     * @param string[]|string|null $remoteCommand
     *   A remote command to run on the host.
     * @param bool $omitUrl
     *   Omit the URL from the command. Use this if the URL is specified in
     *   another way (e.g. when providing the command to rsync or Git).
     * @param bool $autoConfigure
     *   Write or validate SSH configuration automatically after building the
     *   command.
     *
     * @return string
     */
    public function getSshCommand(string $url, array $extraOptions = [], array|string|null $remoteCommand = null, bool $omitUrl = false, bool $autoConfigure = true): string
    {
        $command = 'ssh';
        if (!$omitUrl) {
            $command .= ' ' . OsUtil::escapeShellArg($url);
        }
        if ($args = $this->getSshArgs($url, $extraOptions, $remoteCommand)) {
            $command .= ' ' . implode(' ', array_map(OsUtil::escapeShellArg(...), $args));
        }

        // Configure or validate the session SSH config.
        if ($autoConfigure && !$this->configuredSession && $this->hostIsInternal($url) !== false) {
            try {
                $this->sshConfig->configureSessionSsh();
                $this->configuredSession = true;
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
    public function getEnv(): array
    {
        // Suppress refreshing the certificate while SSH is running through the CLI.
        return [self::SSH_NO_REFRESH_ENV_VAR => '1'];
    }

    /**
     * Finds a host from an SSH URI.
     *
     * @param string $uri
     *
     * @return string|false
     */
    private function getHost(string $uri): string|false
    {
        if (str_contains($uri, '@')) {
            [, $uri] = \explode('@', $uri, 2);
        }
        if (str_contains($uri, '://')) {
            [, $uri] = \explode('://', $uri, 2);
        }
        if (str_contains($uri, ':')) {
            [$uri, ] = \explode(':', $uri, 2);
        }
        if (!preg_match('@^[\p{Ll}0-9-]+\.[\p{Ll}0-9-]+@', $uri)) {
            return false;
        }
        return \parse_url('ssh://' . $uri, PHP_URL_HOST);
    }

    /**
     * Checks if an SSH URI is for an internal (first-party) SSH server.
     *
     * @param string $uri
     *
     * @return bool|null
     *  True if the URI is for an internal server, false if it's external, or null if it cannot be determined.
     */
    public function hostIsInternal(string $uri): ?bool
    {
        $host = $this->getHost($uri);
        if (!$host) {
            return null;
        }
        // Check against the wildcard list.
        $wildcards = $this->config->getWithDefault('ssh.domain_wildcards', []);
        if (!$wildcards) {
            return null;
        }
        foreach ($wildcards as $wildcard) {
            if (str_contains($host, \str_replace('*.', '', $wildcard))) {
                return true;
            }
        }
        return false;
    }
}
