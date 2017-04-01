<?php
namespace Platformsh\Cli\Command\Certificate;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CertificateListCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('certificate:list')
            ->setDescription('List project certificates');
        PropertyFormatter::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition());
        $this->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $project = $this->getSelectedProject();

        $certs = $project->getCertificates();
        if (empty($certs)) {
            $this->stdErr->writeln("No certificates found");

            return 0;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        /** @var \Platformsh\Cli\Service\PropertyFormatter $propertyFormatter */
        $propertyFormatter = $this->getService('property_formatter');

        $header = ['ID', 'Domain(s)', 'Created', 'Expires'];
        $rows = [];
        foreach ($certs as $cert) {
            $rows[] = [
                $cert->id,
                implode("\n", $cert->domains),
                $propertyFormatter->format($cert->created_at, 'created_at'),
                $propertyFormatter->format($cert->expires_at, 'expires_at'),
            ];
        }

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf('Certificates for the project <info>%s</info>:', $this->api()->getProjectLabel($project)));
        }

        $table->render($rows, $header);

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To view a single certificate, run: <info>%s certificate:get <id></info>',
                $this->config()->get('application.executable')
            ));
        }

        return 0;
    }
}
