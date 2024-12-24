<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\SshCert;

use Platformsh\Cli\SshCert\Certifier;
use Platformsh\Cli\Service\SshConfig;
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
    public function __construct(private readonly Certifier $certifier, private readonly PropertyFormatter $propertyFormatter, private readonly SshConfig $sshConfig)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('no-refresh', null, InputOption::VALUE_NONE, 'Do not refresh the certificate if it is invalid')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The certificate property to display');
        PropertyFormatter::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cert = $this->certifier->getExistingCertificate();
        if (!$cert || !$this->certifier->isValid($cert)) {
            if ($input->getOption('no-refresh')) {
                $this->stdErr->writeln('No valid SSH certificate found.');
                $this->stdErr->writeln('To generate a certificate, run this command again without the <comment>--no-refresh</comment> option.');
                return 1;
            }
            if (!$this->sshConfig->checkRequiredVersion()) {
                return 1;
            }
            // Generate a new certificate.
            $cert = $this->certifier->generateCertificate($cert);
        }
        $properties = [
            'filename' => $cert->certificateFilename(),
            'key_filename' => $cert->privateKeyFilename(),
            'key_id' => $cert->metadata()->getKeyId(),
            'key_type' => $cert->metadata()->getKeyType(),
            'valid_after' => $this->propertyFormatter->formatUnixTimestamp($cert->metadata()->getValidAfter()),
            'valid_before' => $this->propertyFormatter->formatUnixTimestamp($cert->metadata()->getValidBefore()),
            'extensions' => $cert->metadata()->getExtensions(),
        ];

        $this->propertyFormatter->displayData($output, $properties, $input->getOption('property'));

        return 0;
    }
}
