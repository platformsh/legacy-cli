<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Domain;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use GuzzleHttp\Exception\ClientException;
use Platformsh\Cli\Model\EnvironmentDomain;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Client\Model\Domain;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'domain:list', description: 'Get a list of all domains', aliases: ['domains'])]
class DomainListCommand extends DomainCommandBase
{
    /** @var array<string, string> */
    private array $tableHeader = [
        'name' => 'Name',
        'ssl' => 'SSL enabled',
        'created_at' => 'Creation date',
        'updated_at' => 'Updated date',
        'registered_name' => 'Registered name',
        'replacement_for' => 'Attached domain',
        'type' => 'Type',
    ];
    /** @var string[] */
    private array $defaultColumns = ['name', 'ssl', 'created_at'];
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
    }

    /**
     * Recursively build rows of the domain table.
     *
     * @param Domain[]|EnvironmentDomain[] $tree
     *
     * @return array<array<string, string>>
     */
    protected function buildDomainRows(array $tree): array
    {
        $rows = [];

        foreach ($tree as $domain) {
            $rows[] = [
                'name' => $domain->id,
                'ssl' => $this->propertyFormatter->format((bool) $domain['ssl']['has_certificate']),
                'created_at' => $this->propertyFormatter->format($domain['created_at'], 'created_at'),
                'updated_at' => $this->propertyFormatter->format($domain['updated_at'], 'updated_at'),
                'registered_name' => $domain->getProperty('registered_name', false, false) ?: '',
                'replacement_for' => $domain->getProperty('replacement_for', false, false) ?: '',
                'type' => $domain->getProperty('type', false, false) ?: '',
            ];
        }

        return $rows;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(envRequired: false));
        $forEnvironment = $input->getOption('environment') !== null;

        $project = $selection->getProject();
        $executable = $this->config->getStr('application.executable');
        $defaultColumns = $this->defaultColumns;

        if ($forEnvironment) {
            $defaultColumns[] = 'replacement_for';
            $httpClient = $this->api->getHttpClient();
            try {
                $domains = EnvironmentDomain::getList($selection->getEnvironment(), $httpClient);
            } catch (ClientException $e) {
                $this->handleApiException($e, $project);
                return 1;
            }
        } else {
            try {
                $domains = $project->getDomains();
            } catch (ClientException $e) {
                $this->handleApiException($e, $project);
                return 1;
            }
        }

        if (empty($domains)) {
            if ($forEnvironment) {
                $environment = $selection->getEnvironment();
                $this->stdErr->writeln(sprintf(
                    'No domains found for the environment %s on the project %s.',
                    $this->api->getEnvironmentLabel($environment),
                    $this->api->getProjectLabel($project),
                ));
                $this->stdErr->writeln('');
                if ($environment->is_main) {
                    $this->stdErr->writeln(sprintf(
                        'Add a domain to the environment by running <info>%s domain:add -e %s [domain-name]</info>',
                        $executable,
                        OsUtil::escapeShellArg($environment->name),
                    ));
                } else {
                    $this->stdErr->writeln(sprintf(
                        'Add a domain to the environment by running <info>%s domain:add -e %s [domain-name] --attach [attach]</info>',
                        $executable,
                        OsUtil::escapeShellArg($environment->name),
                    ));
                }
            } else {
                $this->stdErr->writeln('No domains found for ' . $this->api->getProjectLabel($project) . '.');
                $this->stdErr->writeln('');
                $this->stdErr->writeln(
                    'Add a domain to the project by running <info>' . $executable . ' domain:add [domain-name]</info>',
                );
            }

            return 1;
        }
        $rows = $this->buildDomainRows($domains);

        if (!$this->table->formatIsMachineReadable()) {
            if ($forEnvironment) {
                $this->stdErr->writeln(sprintf(
                    'Domains on the project %s, environment %s:',
                    $this->api->getProjectLabel($project),
                    $this->api->getEnvironmentLabel($selection->getEnvironment()),
                ));
            } else {
                $this->stdErr->writeln(sprintf(
                    'Domains on the project %s:',
                    $this->api->getProjectLabel($project),
                ));
            }
        }

        $this->table->render($rows, $this->tableHeader, $defaultColumns);

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            if ($forEnvironment) {
                $exampleAddArgs = $exampleArgs = '-e ' . OsUtil::escapeShellArg($selection->getEnvironment()->name) . ' [domain-name]';
                if (!$selection->getEnvironment()->is_main) {
                    $exampleAddArgs .= ' --attach [attach]';
                }
            } else {
                $exampleAddArgs = $exampleArgs = '[domain-name]';
            }
            $this->stdErr->writeln([
                sprintf('To add a new domain, run: <info>%s domain:add %s</info>', $executable, $exampleAddArgs),
                sprintf('To view a domain, run: <info>%s domain:get %s</info>', $executable, $exampleArgs),
                sprintf('To delete a domain, run: <info>%s domain:delete %s</info>', $executable, $exampleArgs),
            ]);
        }

        return 0;
    }
}
