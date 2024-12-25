<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Service;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Deployment\EnvironmentDeployment;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'service:list', description: 'List services in the project', aliases: ['services'])]
class ServiceListCommand extends CommandBase
{
    /** @var array<string|int, string> */
    private array $tableHeader = ['Name', 'Type', 'disk' => 'Disk (MiB)', 'Size'];

    public function __construct(private readonly Api $api, private readonly Config $config, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output a list of service names only');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        Table::configureInput($this->getDefinition(), $this->tableHeader);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);

        // Find a list of deployed services.
        $deployment = $this->api
            ->getCurrentDeployment($selection->getEnvironment(), $input->getOption('refresh'));
        $services = $deployment->services;

        if (!count($services)) {
            $this->stdErr->writeln('No services found.');
            $this->recommendOtherCommands($deployment);

            return 0;
        }

        if ($input->getOption('pipe')) {
            $names = array_keys($services);
            sort($names, SORT_NATURAL);
            $output->writeln($names);

            return 0;
        }

        $rows = [];
        foreach ($services as $name => $service) {
            $row = [
                $name,
                $this->propertyFormatter->format($service->type, 'service_type'),
                'disk' => $service->disk !== null ? $service->disk : '',
                $service->size,
            ];
            $rows[] = $row;
        }
        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Services on the project <info>%s</info>, environment <info>%s</info>:',
                $this->api->getProjectLabel($selection->getProject()),
                $this->api->getEnvironmentLabel($selection->getEnvironment()),
            ));
        }

        $this->table->render($rows, $this->tableHeader);

        if (!$this->table->formatIsMachineReadable()) {
            $this->recommendOtherCommands($deployment);
        }

        return 0;
    }

    private function recommendOtherCommands(EnvironmentDeployment $deployment): void
    {
        $lines = [];
        $executable = $this->config->getStr('application.executable');
        if ($deployment->webapps) {
            $lines[] = sprintf(
                'To list applications, run: <info>%s apps</info>',
                $executable,
            );
        }
        if ($deployment->workers) {
            $lines[] = sprintf(
                'To list workers, run: <info>%s workers</info>',
                $executable,
            );
        }
        if ($info = $deployment->getProperty('project_info', false)) {
            if (!empty($info['settings']['sizing_api_enabled']) && $this->config->getBool('api.sizing') && $this->config->isCommandEnabled('resources:set')) {
                $lines[] = sprintf(
                    "To configure resources, run: <info>%s resources:set</info>",
                    $executable,
                );
            }
        }
        if ($lines) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln($lines);
        }
    }
}
