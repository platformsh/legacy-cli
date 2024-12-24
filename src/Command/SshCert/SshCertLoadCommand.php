<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\SshCert;

use Platformsh\Cli\Service\Io;
use Platformsh\Cli\SshCert\Certifier;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\SshConfig;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\SshCert\Certificate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ssh-cert:load', description: 'Generate an SSH certificate')]
class SshCertLoadCommand extends CommandBase
{
    public function __construct(private readonly Certifier $certifier, private readonly Config $config, private readonly Io $io, private readonly PropertyFormatter $propertyFormatter, private readonly QuestionHelper $questionHelper, private readonly SshConfig $sshConfig)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('refresh-only', null, InputOption::VALUE_NONE, 'Only refresh the certificate, if necessary (do not write SSH config)')
            ->addOption('new', null, InputOption::VALUE_NONE, 'Force the certificate to be refreshed')
            ->addOption('new-key', null, InputOption::VALUE_NONE, 'Force a new key pair to be generated');
        $help = 'This command checks if a valid SSH certificate is present, and generates a new one if necessary.';
        if ($this->config->getBool('ssh.auto_load_cert')) {
            $envPrefix = $this->config->getStr('application.env_prefix');
            $help .= "\n\nCertificates allow you to make SSH connections without having previously uploaded a public key. They are more secure than keys and they allow for other features."
                . "\n\nNormally the certificate is loaded automatically during login, or when making an SSH connection. So this command is seldom needed."
                . "\n\nIf you want to set up certificates without login and without an SSH-related command, for example if you are writing a script that uses an API token via an environment variable, then you would probably want to run this command explicitly."
                . " For unattended scripts, remember to turn off interaction via --yes or the {$envPrefix}NO_INTERACTION environment variable.";
        }
        $this->setHelp(\wordwrap($help));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io->warnAboutDeprecatedOptions(['new-key'], 'The --new-key option is deprecated. Use --new instead.');

        $sshCert = $this->certifier->getExistingCertificate();

        $refreshOnly = $input->getOption('refresh-only');

        $refresh = true;
        if (getenv(Ssh::SSH_NO_REFRESH_ENV_VAR)) {
            if ($refreshOnly && $this->stdErr->isQuiet()) {
                return 0;
            }
            $this->stdErr->writeln(sprintf('Not refreshing SSH certificate (<comment>%s</comment> variable is set)', Ssh::SSH_NO_REFRESH_ENV_VAR));
            $refresh = false;
        }

        if ($sshCert
            && !$input->getOption('new')
            && !$input->getOption('new-key')
            && $this->certifier->isValid($sshCert)) {
            if ($refreshOnly && $this->stdErr->isQuiet()) {
                return 0;
            }
            $this->stdErr->writeln('A valid SSH certificate exists');
            $this->displayCertificate($sshCert);
            $refresh = false;
        }

        if ($refresh) {
            if (!$this->sshConfig->checkRequiredVersion()) {
                return 1;
            }
            if ($refreshOnly && $this->stdErr->isQuiet()) {
                $this->certifier->generateCertificate($sshCert, $input->getOption('new-key'));
                return 0;
            }
            $this->stdErr->writeln('Generating SSH certificate...');
            $sshCert = $this->certifier->generateCertificate($sshCert, $input->getOption('new-key'));
            $this->displayCertificate($sshCert);
        }

        if ($refreshOnly) {
            return 0;
        }

        $this->sshConfig->configureHostKeys();
        $hasSessionConfig = $this->sshConfig->configureSessionSsh();
        $success = !$hasSessionConfig || $this->sshConfig->addUserSshConfig($this->questionHelper);

        return $success ? 0 : 1;
    }

    private function displayCertificate(Certificate $cert): void
    {
        $validBefore = $cert->metadata()->getValidBefore();
        $expires = $this->propertyFormatter->formatUnixTimestamp($validBefore);
        $expiresWithColor = $validBefore > time() ? '<fg=green>' . $expires . '</>' : $expires;
        $mfaWithColor = $cert->hasMfa() ? '<fg=green>verified</>' : 'not verified';
        $interactivityMode = '<fg=green>' . ($cert->isApp() ? 'app' : 'interactive') . '</>';
        $this->stdErr->writeln([
            "  Expires at: $expiresWithColor",
            "  Multi-factor authentication: $mfaWithColor",
            "  Mode: $interactivityMode",
        ]);
        if ($ssoProviders = $cert->ssoProviders()) {
            $this->stdErr->writeln("  SSO provider(s): <fg=green>" . implode('</>, <fg=green>', $ssoProviders) . '</>');
        }

        $this->stdErr->writeln('The certificate will be automatically refreshed when necessary.');
    }
}
