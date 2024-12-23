<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Certificate;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Certificate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'certificate:list', description: 'List project certificates', aliases: ['certificates', 'certs'])]
class CertificateListCommand extends CommandBase
{
    /** @var array<string, string> */
    private array $tableHeader = [
        'id' => 'ID',
        'domains' => 'Domain(s)',
        'created' => 'Created',
        'expires' => 'Expires',
        'issuer' => 'Issuer',
    ];
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Filter by domain name (case-insensitive search)');
        $this->addOption('exclude-domain', null, InputOption::VALUE_REQUIRED, 'Exclude certificates, matching by domain name (case-insensitive search)');
        $this->addOption('issuer', null, InputOption::VALUE_REQUIRED, 'Filter by issuer');
        $this->addOption('only-auto', null, InputOption::VALUE_NONE, 'Show only auto-provisioned certificates');
        $this->addOption('no-auto', null, InputOption::VALUE_NONE, 'Show only manually added certificates');
        $this->addOption('ignore-expiry', null, InputOption::VALUE_NONE, 'Show both expired and non-expired certificates');
        $this->addOption('only-expired', null, InputOption::VALUE_NONE, 'Show only expired certificates');
        $this->addOption('no-expired', null, InputOption::VALUE_NONE, 'Show only non-expired certificates (default)');
        $this->addOption('pipe-domains', null, InputOption::VALUE_NONE, 'Only return a list of domain names covered by the certificates');
        PropertyFormatter::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition(), $this->tableHeader);
        $this->selector->addProjectOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->addExample('Output a list of domains covered by valid certificates', '--pipe-domains --no-expired');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);

        // Set --no-expired by default, if --ignore-expiry and --only-expired
        // are not supplied.
        if (!$input->getOption('ignore-expiry') && !$input->getOption('only-expired')) {
            $input->setOption('no-expired', true);
        }

        $filterOptions = ['domain', 'exclude-domain', 'issuer', 'only-auto', 'no-auto', 'only-expired', 'no-expired'];
        $filters = array_filter(array_intersect_key($input->getOptions(), array_flip($filterOptions)));

        $project = $selection->getProject();

        $certs = $project->getCertificates();

        $this->filterCerts($certs, $filters);

        if (!empty($filters) && !$input->getOption('pipe-domains')) {
            $filtersUsed = '<comment>--'
                . implode('</comment>, <comment>--', array_keys($filters))
                . '</comment>';
            $this->stdErr->writeln(sprintf('Filters in use: %s', $filtersUsed));
            $this->stdErr->writeln('');
        }

        if (empty($certs)) {
            $this->stdErr->writeln("No certificates found");

            return 0;
        }

        if ($input->getOption('pipe-domains')) {
            foreach ($certs as $cert) {
                foreach ($cert->domains as $domain) {
                    $output->writeln($domain);
                }
            }

            return 0;
        }

        $rows = [];
        foreach ($certs as $cert) {
            $rows[] = [
                'id' => $cert->id,
                'domains' => implode("\n", $cert->domains),
                'created' => $this->propertyFormatter->format($cert->created_at, 'created_at'),
                'expires' => $this->propertyFormatter->format($cert->expires_at, 'expires_at'),
                'issuer' => $this->getCertificateIssuerByAlias($cert, 'commonName') ?: '',
            ];
        }

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf('Certificates for the project <info>%s</info>:', $this->api->getProjectLabel($project)));
        }

        $this->table->render($rows, $this->tableHeader);

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To view a single certificate, run: <info>%s certificate:get <id></info>',
                $this->config->getStr('application.executable'),
            ));
        }

        return 0;
    }

    /**
     * @param Certificate[] $certs
     * @param array<string, mixed> $filters
     * @return void
     */
    protected function filterCerts(array &$certs, array $filters): void
    {
        foreach ($filters as $filter => $value) {
            switch ($filter) {
                case 'domain':
                case 'exclude-domain':
                    $include = $filter === 'domain';
                    $certs = array_filter($certs, function (Certificate $cert) use ($value, $include): bool {
                        foreach ($cert->domains as $domain) {
                            if (stripos($domain, (string) $value) !== false) {
                                return $include;
                            }
                        }

                        return !$include;
                    });
                    break;

                case 'issuer':
                    $certs = array_filter($certs, function (Certificate $cert) use ($value): bool {
                        foreach ($cert->issuer as $issuer) {
                            if (isset($issuer['value']) && $issuer['value'] === $value) {
                                return true;
                            }
                        }

                        return false;
                    });
                    break;

                case 'only-auto':
                    $certs = array_filter($certs, fn(Certificate $cert): bool => $cert->is_provisioned);
                    break;

                case 'no-auto':
                    $certs = array_filter($certs, fn(Certificate $cert): bool => !$cert->is_provisioned);
                    break;

                case 'no-expired':
                    $certs = array_filter($certs, fn(Certificate $cert): bool => !$this->isExpired($cert));
                    break;

                case 'only-expired':
                    $certs = array_filter($certs, fn(Certificate $cert): bool => $this->isExpired($cert));
                    break;
            }
        }
    }

    /**
     * Check if a certificate has expired.
     *
     * @param Certificate $cert
     *
     * @return bool
     */
    private function isExpired(Certificate $cert): bool
    {
        return time() >= strtotime($cert->expires_at);
    }

    private function getCertificateIssuerByAlias(Certificate $cert, string $alias): string|false
    {
        foreach ($cert->issuer as $issuer) {
            if (isset($issuer['alias'], $issuer['value']) && $issuer['alias'] === $alias) {
                return $issuer['value'];
            }
        }

        return false;
    }
}
