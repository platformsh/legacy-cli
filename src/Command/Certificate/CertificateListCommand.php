<?php
namespace Platformsh\Cli\Command\Certificate;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Certificate;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CertificateListCommand extends CommandBase
{

    protected static $defaultName = 'certificate:list';

    private $selector;
    private $formatter;
    private $table;

    public function __construct(Selector $selector, PropertyFormatter $formatter, Table $table)
    {
        $this->selector = $selector;
        $this->formatter = $formatter;
        $this->table = $table;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['certificates'])
            ->setDescription('List project certificates');
        $this->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Filter by domain name (case-insensitive search)');
        $this->addOption('issuer', null, InputOption::VALUE_REQUIRED, 'Filter by issuer');
        $this->addOption('only-auto', null, InputOption::VALUE_NONE, 'Show only auto-provisioned certificates');
        $this->addOption('no-auto', null, InputOption::VALUE_NONE, 'Show only manually added certificates');
        $this->addOption('only-expired', null, InputOption::VALUE_NONE, 'Show only expired certificates');
        $this->addOption('no-expired', null, InputOption::VALUE_NONE, 'Show only non-expired certificates');

        $definition = $this->getDefinition();
        $this->formatter->configureInput($definition);
        $this->table->configureInput($definition);
        $this->selector->addProjectOption($definition);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->selector->getSelection($input)->getProject();

        $filterOptions = ['domain', 'issuer', 'only-auto', 'no-auto', 'only-expired', 'no-expired'];
        $filters = array_filter(array_intersect_key($input->getOptions(), array_flip($filterOptions)));

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

        $header = ['ID', 'Domain(s)', 'Created', 'Expires', 'Issuer'];
        $rows = [];
        foreach ($certs as $cert) {
            $rows[] = [
                $cert->id,
                implode("\n", $cert->domains),
                $this->formatter->format($cert->created_at, 'created_at'),
                $this->formatter->format($cert->expires_at, 'expires_at'),
                $this->getCertificateIssuerByAlias($cert, 'commonName') ?: '',
            ];
        }

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf('Certificates for the project <info>%s</info>:', $this->api()->getProjectLabel($project)));
        }

        $this->table->render($rows, $header);

        if (!$this->table->formatIsMachineReadable()) {
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

                case 'no-expired':
                    $certs = array_filter($certs, function (Certificate $cert) {
                        return !$this->isExpired($cert);
                    });
                    break;

                case 'only-expired':
                    $certs = array_filter($certs, function (Certificate $cert) {
                        return $this->isExpired($cert);
                    });
                    break;
            }
        }
    }

    /**
     * Check if a certificate has expired.
     *
     * @param \Platformsh\Client\Model\Certificate $cert
     *
     * @return bool
     */
    private function isExpired(Certificate $cert)
    {
        return time() >= strtotime($cert->expires_at);
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
