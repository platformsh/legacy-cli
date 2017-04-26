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
            ->addArgument('id', InputArgument::OPTIONAL, 'The certificate ID')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The certificate property to view');
        PropertyFormatter::configureInput($this->getDefinition());
        $this->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $project = $this->getSelectedProject();

        $id = $input->getArgument('id');
        if (!$id) {
            $certs = $project->getCertificates();
            if (!$input->isInteractive() || count($certs) > 5) {
                $this->stdErr->writeln('The certificate ID is required.');

                return 1;
            }
            $options = [];
            foreach ($certs as $cert) {
                $options[$cert->id] = sprintf(
                    "%s (%s)",
                    substr($cert->id, 0, 12) . '...',
                    implode(', ', $cert->domains)
                );
            }
            /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');
            $id = $questionHelper->choose($options, 'Enter a number to choose a certificate:');
        }

        $cert = $project->getCertificate($id);
        if (!$cert) {
            $this->stdErr->writeln(sprintf('Certificate not found: %s', $id));

            return 1;
        }

        /** @var PropertyFormatter $propertyFormatter */
        $propertyFormatter = $this->getService('property_formatter');

        $propertyFormatter->displayData($output, $cert->getProperties(), $input->getOption('property'));

        return 0;
    }
}
