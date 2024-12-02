<?php
namespace Platformsh\Cli\Command\SshCert;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\PropertyFormatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ssh-cert:info', description: 'Display information about the current SSH certificate')]
class SshCertInfoCommand extends CommandBase
{
    protected bool $hiddenInList = true;

    protected function configure()
    {
        $this
            ->addOption('no-refresh', null, InputOption::VALUE_NONE, 'Do not refresh the certificate if it is invalid')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The certificate property to display');
        PropertyFormatter::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Initialize the API service to ensure event listeners etc.
        $this->api();

        /** @var \Platformsh\Cli\SshCert\Certifier $certifier */
        $certifier = $this->getService('certifier');
        /** @var \Platformsh\Cli\Service\SshConfig $sshConfig */
        $sshConfig = $this->getService('ssh_config');

        $cert = $certifier->getExistingCertificate();
        if (!$cert || !$certifier->isValid($cert)) {
            if ($input->getOption('no-refresh')) {
                $this->stdErr->writeln('No valid SSH certificate found.');
                $this->stdErr->writeln('To generate a certificate, run this command again without the <comment>--no-refresh</comment> option.');
                return 1;
            }
            if (!$sshConfig->checkRequiredVersion()) {
                return 1;
            }
            // Generate a new certificate.
            $cert = $certifier->generateCertificate($cert);
        }

        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');
        $properties = [
            'filename' => $cert->certificateFilename(),
            'key_filename' => $cert->privateKeyFilename(),
            'key_id' => $cert->metadata()->getKeyId(),
            'key_type' => $cert->metadata()->getKeyType(),
            'valid_after' => $formatter->formatUnixTimestamp($cert->metadata()->getValidAfter()),
            'valid_before' => $formatter->formatUnixTimestamp($cert->metadata()->getValidBefore()),
            'extensions' => $cert->metadata()->getExtensions(),
        ];

        $formatter->displayData($output, $properties, $input->getOption('property'));

        return 0;
    }
}
