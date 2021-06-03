<?php
namespace Platformsh\Cli\Command\SshCert;

use Platformsh\Cli\Command\CommandBase;
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
            ->addOption('new-key', null, InputOption::VALUE_NONE, '[Deprecated] Use --new instead')
            ->setDescription('Generate an SSH certificate');
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
        if ($sshCert
            && !$input->getOption('new')
            && !$input->getOption('new-key')
            && !$sshCert->hasExpired()
            && $sshCert->metadata()->getKeyId() === $this->api()->getMyUserId()) {
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
            $sshCert = $certifier->generateCertificate();
            $this->displayCertificate($sshCert);
        }

        $hasSessionConfig = $sshConfig->configureSessionSsh();

        if ($input->getOption('refresh-only')) {
            return 0;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $success = !$hasSessionConfig || $sshConfig->addUserSshConfig($questionHelper);

        return $success ? 0 : 1;
    }

    private function displayCertificate(Certificate $cert)
    {
        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');
        $expires = $formatter->formatDate($cert->metadata()->getValidBefore());
        $expiresWithColor = $expires < time() ? '<fg=green>' . $expires . '</>' : $expires;
        $mfaWithColor = $cert->hasMfa() ? '<fg=green>verified</>' : 'not verified';
        $interactivityMode = $cert->isApp() ? 'app' : 'interactive';
        $this->stdErr->writeln([
            "  Expires at: $expiresWithColor",
            "  Multi-factor authentication: $mfaWithColor",
            "  Mode: <info>$interactivityMode</info>",
        ]);
        $this->stdErr->writeln('The certificate will be automatically refreshed when necessary.');
    }
}
