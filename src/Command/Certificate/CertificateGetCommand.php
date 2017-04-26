<?php
namespace Platformsh\Cli\Command\Certificate;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\PropertyFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CertificateGetCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('certificate:get')
            ->setDescription('View a certificate')
            ->addArgument('id', InputArgument::REQUIRED, 'The certificate ID (or the start of it)')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The certificate property to view');
        PropertyFormatter::configureInput($this->getDefinition());
        $this->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $project = $this->getSelectedProject();

        $id = $input->getArgument('id');
        $cert = $project->getCertificate($id);
        if (!$cert) {
            $cert = $this->matchCertificateId($id, $project->getCertificates());
            if (!$cert) {
                $this->stdErr->writeln(sprintf('Certificate not found: %s', $id));

                return 1;
            }
        }

        /** @var PropertyFormatter $propertyFormatter */
        $propertyFormatter = $this->getService('property_formatter');

        $propertyFormatter->displayData($output, $cert->getProperties(), $input->getOption('property'));

        return 0;
    }

    /**
     * @param string                                 $id
     * @param \Platformsh\Client\Model\Certificate[] $certs
     *
     * @return \Platformsh\Client\Model\Certificate|null
     */
    protected function matchCertificateId($id, array $certs)
    {
        if (strlen($id) > 5) {
            foreach ($certs as $candidate) {
                if (strpos($candidate->id, $id) === 0) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
