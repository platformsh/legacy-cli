<?php
namespace Platformsh\Cli\Command\SshCert;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\SshCert\Certificate;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SshCertLoadCommand extends CommandBase
{
    protected function configure()
    {
        $this
            ->setName('ssh-cert:load')
            ->addOption('refresh-only', null, InputOption::VALUE_NONE, 'Only refresh the certificate, if necessary (do not write SSH config)')
            ->addOption('new', null, InputOption::VALUE_NONE, 'Force the certificate to be refreshed')
            ->addOption('new-key', null, InputOption::VALUE_NONE, 'Force a new key pair to be generated')
            ->setDescription('Generate an SSH certificate');
        $help = 'This command checks if a valid SSH certificate is present, and generates a new one if necessary.';
        if ($this->config()->getWithDefault('ssh.auto_load_cert', false)) {
            $envPrefix = $this->config()->get('application.env_prefix');
            $help .= "\n\nCertificates allow you to make SSH connections without having previously uploaded a public key. They are more secure than keys and they allow for other features."
                . "\n\nNormally the certificate is loaded automatically during login, or when making an SSH connection. So this command is seldom needed."
                . "\n\nIf you want to set up certificates without login and without an SSH-related command, for example if you are writing a script that uses an API token via an environment variable, then you would probably want to run this command explicitly."
                . " For unattended scripts, remember to turn off interaction via --yes or the {$envPrefix}NO_INTERACTION environment variable.";
        }
        $this->setHelp(\wordwrap($help));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->warnAboutDeprecatedOptions(['new-key'], 'The --new-key option is deprecated. Use --new instead.');

        // Initialize the API service to ensure event listeners etc.
        $this->api();

        /** @var \Platformsh\Cli\SshCert\Certifier $certifier */
        $certifier = $this->getService('certifier');

        $sshCert = $certifier->getExistingCertificate();

        $refresh = true;
        if (getenv(Ssh::SSH_NO_REFRESH_ENV_VAR)) {
            $this->stdErr->writeln(sprintf('Not refreshing SSH certificate (<comment>%s</comment> variable is set)', Ssh::SSH_NO_REFRESH_ENV_VAR));
            $refresh = false;
        }

        if ($sshCert
            && !$input->getOption('new')
            && !$input->getOption('new-key')
            && $certifier->isValid($sshCert)) {
            $this->stdErr->writeln('A valid SSH certificate exists');
            $this->displayCertificate($sshCert);
            $refresh = false;
        }

        /** @var \Platformsh\Cli\Service\SshConfig $sshConfig */
        $sshConfig = $this->getService('ssh_config');

        if ($refresh) {
            if (!$sshConfig->checkRequiredVersion()) {
                return 1;
            }
            $this->stdErr->writeln('Generating SSH certificate...');
            $sshCert = $certifier->generateCertificate($sshCert, $input->getOption('new-key'));
            $this->displayCertificate($sshCert);
        }

        if ($input->getOption('refresh-only')) {
            return 0;
        }

        $sshConfig->configureHostKeys();
        $hasSessionConfig = $sshConfig->configureSessionSsh();

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $success = !$hasSessionConfig || $sshConfig->addUserSshConfig($questionHelper);

        return $success ? 0 : 1;
    }

    private function displayCertificate(Certificate $cert)
    {
        $validBefore = $cert->metadata()->getValidBefore();
        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');
        $expires = $formatter->formatUnixTimestamp($validBefore);
        $expiresWithColor = $validBefore > time() ? '<fg=green>' . $expires . '</>' : $expires;
        $mfaWithColor = $cert->hasMfa() ? '<fg=green>verified</>' : 'not verified';
        $interactivityMode = '<fg=green>' . ($cert->isApp() ? 'app' : 'interactive') . '</>';
        $this->stdErr->writeln([
            "  Expires at: $expiresWithColor",
            "  Multi-factor authentication: $mfaWithColor",
            "  Mode: $interactivityMode",
        ]);
        $this->stdErr->writeln('The certificate will be automatically refreshed when necessary.');
    }
}
