<?php
namespace Platformsh\Cli\Command\SshCert;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\SshConfig;
use Platformsh\Cli\SshCert\Certificate;
use Platformsh\Cli\SshCert\Certifier;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SshCertInfoCommand extends CommandBase
{
    protected static $defaultName = 'ssh-cert:info';
    protected static $defaultDescription = 'Display information about the current SSH certificate';

    protected $hiddenInList = true;

    private $api;
    private $certifier;
    private $formatter;
    private $sshConfig;

    public function __construct(Api $api, Certifier $certifier, PropertyFormatter $formatter, SshConfig $sshConfig)
    {
        $this->api = $api;
        $this->certifier = $certifier;
        $this->formatter = $formatter;
        $this->sshConfig = $sshConfig;
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('ssh-cert:info')
            ->addOption('no-refresh', null, InputOption::VALUE_NONE, 'Do not refresh the certificate if it is invalid')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The certificate property to display');
        $this->formatter->configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cert = $this->certifier->getExistingCertificate();
        if (!$cert || !$this->isValid($cert)) {
            if ($input->getOption('no-refresh')) {
                $this->stdErr->writeln('No valid SSH certificate found.');
                $this->stdErr->writeln('To generate a certificate, run this command again without the <comment>--no-refresh</comment> option.');
                return 1;
            }
            if (!$this->sshConfig->checkRequiredVersion()) {
                return 1;
            }
            // Generate a new certificate.
            $cert = $this->certifier->generateCertificate();
        }

        $properties = [
            'filename' => $cert->certificateFilename(),
            'key_filename' => $cert->privateKeyFilename(),
            'key_id' => $cert->metadata()->getKeyId(),
            'key_type' => $cert->metadata()->getKeyType(),
            'valid_after' => $this->formatter->formatUnixTimestamp($cert->metadata()->getValidAfter()),
            'valid_before' => $this->formatter->formatUnixTimestamp($cert->metadata()->getValidBefore()),
            'extensions' => $cert->metadata()->getExtensions(),
        ];

        $this->formatter->displayData($output, $properties, $input->getOption('property'));

        return 0;
    }

    private function isValid(Certificate $cert) {
        return !$cert->hasExpired(0) && $cert->metadata()->getKeyId() === $this->api->getMyUserId();
    }
}
