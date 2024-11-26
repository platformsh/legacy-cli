<?php
namespace Platformsh\Cli\Command\Service;

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
    private $tableHeader = ['Name', 'Type', 'disk' => 'Disk (MiB)', 'Size'];

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output a list of service names only');
        $this->addProjectOption()
            ->addEnvironmentOption();
        Table::configureInput($this->getDefinition(), $this->tableHeader);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateInput($input);

        // Find a list of deployed services.
        $deployment = $this->api()
            ->getCurrentDeployment($this->getSelectedEnvironment(), $input->getOption('refresh'));
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

        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $rows = [];
        foreach ($services as $name => $service) {
            $row = [
                $name,
                $formatter->format($service->type, 'service_type'),
                'disk' => $service->disk !== null ? $service->disk : '',
                $service->size,
            ];
            $rows[] = $row;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Services on the project <info>%s</info>, environment <info>%s</info>:',
                $this->api()->getProjectLabel($this->getSelectedProject()),
                $this->api()->getEnvironmentLabel($this->getSelectedEnvironment())
            ));
        }

        $table->render($rows, $this->tableHeader);

        if (!$table->formatIsMachineReadable()) {
            $this->recommendOtherCommands($deployment);
        }

        return 0;
    }

    private function recommendOtherCommands(EnvironmentDeployment $deployment)
    {
        $lines = [];
        $executable = $this->config()->get('application.executable');
        if ($deployment->webapps) {
            $lines[] = sprintf(
                'To list applications, run: <info>%s apps</info>',
                $executable
            );
        }
        if ($deployment->workers) {
            $lines[] = sprintf(
                'To list workers, run: <info>%s workers</info>',
                $executable
            );
        }
        if ($info = $deployment->getProperty('project_info', false)) {
            if (!empty($info['settings']['sizing_api_enabled']) && $this->config()->get('api.sizing') && $this->config()->isCommandEnabled('resources:set')) {
                $lines[] = sprintf(
                    "To configure resources, run: <info>%s resources:set</info>",
                    $executable
                );
            }
        }
        if ($lines) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln($lines);
        }
    }
}
