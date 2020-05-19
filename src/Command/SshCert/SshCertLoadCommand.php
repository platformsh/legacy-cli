<?php
namespace Platformsh\Cli\Command\SshCert;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\SshCert\Certificate;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SshCertLoadCommand extends CommandBase
{
    protected $hiddenInList = true;

    protected function configure()
    {
        $this
            ->setName('ssh-cert:load')
            ->addOption('refresh-only', null, InputOption::VALUE_NONE, 'Only refresh the certificate (do not write SSH config)')
            ->addOption('new', null, InputOption::VALUE_NONE, 'Force the certificate to be refreshed')
            ->addOption('new-key', null, InputOption::VALUE_NONE, 'Force the certificate to be refreshed with a new SSH key pair')
            ->setDescription('Generate a new certificate from the certifier API');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Initialize the API service to ensure event listeners etc.
        $this->api();

        /** @var \Platformsh\Cli\SshCert\Certifier $certifier */
        $certifier = $this->getService('certifier');

        $sshCert = $certifier->getExistingCertificate();

        $refresh = true;
        if ($sshCert && !$input->getOption('new') && !$input->getOption('new-key') && !$sshCert->hasExpired()) {
            $this->stdErr->writeln('A valid SSH certificate exists');
            $this->displayCertificate($sshCert);
            $refresh = false;
        }

        if ($refresh) {
            $this->stdErr->writeln('Generating SSH certificate...');
            $sshCert = $certifier->generateCertificate($input->getOption('new-key'));
            $this->displayCertificate($sshCert);
        }

        if ($input->getOption('refresh-only')) {
            return 0;
        }

        /** @var QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $success = $certifier->addUserSshConfig($questionHelper);

        return $success ? 0 : 1;
    }

    private function displayCertificate(Certificate $cert)
    {
        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');
        $validBefore = $formatter->formatDate($cert->metadata()->getValidBefore());
        $claims = [
            'Valid until' => $validBefore < time() ? '<fg=green>' . $validBefore . '</>' : $validBefore,
            'Multi-factor authentication' => $cert->hasMfa() ? '<fg=green>verified</>' : 'not verified',
        ];
        foreach ($claims as $claim => $value) {
            $this->stdErr->writeln("  $claim: $value");
        }
    }
}
