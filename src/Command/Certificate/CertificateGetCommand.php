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
            try {
                $cert = $this->api()->matchPartialId($id, $project->getCertificates(), 'Certificate');
            } catch (\InvalidArgumentException $e) {
                $this->stdErr->writeln($e->getMessage());
                return 1;
            }
        }

        /** @var PropertyFormatter $propertyFormatter */
        $propertyFormatter = $this->getService('property_formatter');

        $propertyFormatter->displayData($output, $cert->getProperties(), $input->getOption('property'));

        return 0;
    }
}
