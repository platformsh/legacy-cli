<?php
namespace Platformsh\Cli\Command\Domain;

use GuzzleHttp\Exception\ClientException;
use Platformsh\Cli\Model\EnvironmentDomain;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Client\Model\Domain;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DomainListCommand extends DomainCommandBase
{
    private $tableHeader = [
        'name' => 'Name',
        'ssl' => 'SSL enabled',
        'created_at' => 'Creation date',
        'updated_at' => 'Updated date',
        'registered_name' => 'Registered name',
        'replacement_for' => 'Attached domain',
        'type' => 'Type',
    ];
    private $defaultColumns = ['name', 'ssl', 'created_at'];

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('domain:list')
            ->setAliases(['domains'])
            ->setDescription('Get a list of all domains');
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
        $this->addProjectOption()->addEnvironmentOption();
    }

    /**
     * Recursively build rows of the domain table.
     *
     * @param Domain[]|EnvironmentDomain[] $tree
     *
     * @return array
     */
    protected function buildDomainRows(array $tree)
    {
        $rows = [];

        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        foreach ($tree as $domain) {
            $rows[] = [
                'name' => $domain->id,
                'ssl' => $formatter->format((bool) $domain['ssl']['has_certificate']),
                'created_at' => $formatter->format($domain['created_at'], 'created_at'),
                'updated_at' => $formatter->format($domain['updated_at'], 'updated_at'),
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
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, true);
        $forEnvironment = $input->getOption('environment') !== null;

        $project = $this->getSelectedProject();
        $executable = $this->config()->get('application.executable');
        $defaultColumns = $this->defaultColumns;

        if ($forEnvironment) {
            $defaultColumns[] = 'replacement_for';
            $httpClient = $this->api()->getHttpClient();
            try {
                $domains = EnvironmentDomain::getList($this->getSelectedEnvironment(), $httpClient);
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
                $environment = $this->getSelectedEnvironment();
                $this->stdErr->writeln(sprintf(
                    'No domains found for the environment %s on the project %s.',
                    $this->api()->getEnvironmentLabel($environment),
                    $this->api()->getProjectLabel($project)
                ));
                $this->stdErr->writeln('');
                if ($environment->is_main) {
                    $this->stdErr->writeln(sprintf(
                        'Add a domain to the environment by running <info>%s domain:add -e %s [domain-name]</info>',
                        $executable,
                        OsUtil::escapeShellArg($environment->name)
                    ));
                } else {
                    $this->stdErr->writeln(sprintf(
                        'Add a domain to the environment by running <info>%s domain:add -e %s [domain-name] --attach [attach]</info>',
                        $executable,
                        OsUtil::escapeShellArg($environment->name)
                    ));
                }
            }
            else {
                $this->stdErr->writeln('No domains found for ' . $this->api()->getProjectLabel($project) . '.');
                $this->stdErr->writeln('');
                $this->stdErr->writeln(
                    'Add a domain to the project by running <info>' . $executable . ' domain:add [domain-name]</info>'
                );
            }

            return 1;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        $rows = $this->buildDomainRows($domains);

        if (!$table->formatIsMachineReadable()) {
            if ($forEnvironment) {
                $this->stdErr->writeln(sprintf(
                    'Domains on the project %s, environment %s:',
                    $this->api()->getProjectLabel($project),
                    $this->api()->getEnvironmentLabel($this->getSelectedEnvironment())
                ));
            } else {
                $this->stdErr->writeln(sprintf(
                    'Domains on the project %s:',
                    $this->api()->getProjectLabel($project)
                ));
            }
        }

        $table->render($rows, $this->tableHeader, $defaultColumns);

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            if ($forEnvironment) {
                $exampleAddArgs = $exampleArgs = '-e ' . OsUtil::escapeShellArg($this->getSelectedEnvironment()->name) . ' [domain-name]';
                if (!$this->getSelectedEnvironment()->is_main) {
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
