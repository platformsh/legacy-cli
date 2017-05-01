<?php
namespace Platformsh\Cli\Command\Certificate;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Certificate;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CertificateListCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('certificate:list')
            ->setAliases(['certificates'])
            ->setDescription('List project certificates');
        $this->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Filter by domain name (case-insensitive search)');
        $this->addOption('issuer', null, InputOption::VALUE_REQUIRED, 'Filter by issuer');
        $this->addOption('only-auto', null, InputOption::VALUE_NONE, 'Show only auto-provisioned certificates');
        $this->addOption('no-auto', null, InputOption::VALUE_NONE, 'Show only manually added certificates');
        PropertyFormatter::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition());
        $this->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $filterOptions = ['domain', 'issuer', 'only-auto', 'no-auto'];
        $filters = array_filter(array_intersect_key($input->getOptions(), array_flip($filterOptions)));

        $project = $this->getSelectedProject();

        $certs = $project->getCertificates();

        $this->filterCerts($certs, $filters);

        if (!empty($filters)) {
            $filtersUsed = '<comment>--'
                . implode('</comment>, <comment>--', array_keys($filters))
                . '</comment>';
            $this->stdErr->writeln(sprintf('Filters in use: %s', $filtersUsed));
        }

        if (empty($certs)) {
            $this->stdErr->writeln("No certificates found");

            return 0;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        /** @var \Platformsh\Cli\Service\PropertyFormatter $propertyFormatter */
        $propertyFormatter = $this->getService('property_formatter');

        $header = ['ID', 'Domain(s)', 'Created', 'Expires', 'Issuer'];
        $rows = [];
        foreach ($certs as $cert) {
            $rows[] = [
                $cert->id,
                implode("\n", $cert->domains),
                $propertyFormatter->format($cert->created_at, 'created_at'),
                $propertyFormatter->format($cert->expires_at, 'expires_at'),
                $this->getCertificateIssuerByAlias($cert, 'commonName') ?: '',
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

    protected function filterCerts(array &$certs, array $filters)
    {
        foreach ($filters as $filter => $value) {
            switch ($filter) {
                case 'domain':
                    $certs = array_filter($certs, function (Certificate $cert) use ($value) {
                        foreach ($cert->domains as $domain) {
                            if (stripos($domain, $value) !== false) {
                                return true;
                            }
                        }

                        return false;
                    });
                    break;

                case 'issuer':
                    $certs = array_filter($certs, function (Certificate $cert) use ($value) {
                        foreach ($cert->issuer as $issuer) {
                            if (isset($issuer['value']) && $issuer['value'] === $value) {
                                return true;
                            }
                        }

                        return false;
                    });
                    break;

                case 'only-auto':
                    $certs = array_filter($certs, function (Certificate $cert) {
                        return (bool) $cert->is_provisioned;
                    });
                    break;

                case 'no-auto':
                    $certs = array_filter($certs, function (Certificate $cert) {
                        return !$cert->is_provisioned;
                    });
                    break;
            }
        }
    }

    /**
     * @param \Platformsh\Client\Model\Certificate $cert
     * @param string                               $alias
     *
     * @return string|bool
     */
    protected function getCertificateIssuerByAlias(Certificate $cert, $alias) {
        foreach ($cert->issuer as $issuer) {
            if (isset($issuer['alias'], $issuer['value']) && $issuer['alias'] === $alias) {
                return $issuer['value'];
            }
        }

        return false;
    }
}
