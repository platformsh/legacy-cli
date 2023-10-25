<?php
namespace Platformsh\Cli\Command\Worker;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Deployment\EnvironmentDeployment;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class WorkerListCommand extends CommandBase
{
    private $tableHeader = ['Name', 'Type', 'Commands'];

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('worker:list')
            ->setAliases(['workers'])
            ->setDescription('Get a list of all deployed workers')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output a list of worker names only');
        $this->addProjectOption()
            ->addEnvironmentOption();
        Table::configureInput($this->getDefinition(), $this->tableHeader);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->chooseEnvFilter = $this->filterEnvsByState(['active']);
        $this->validateInput($input);

        $deployment = $this->api()
            ->getCurrentDeployment($this->getSelectedEnvironment(), $input->getOption('refresh'));

        $workers = $deployment->workers;
        if (empty($workers)) {
            $this->stdErr->writeln('No workers found.');
            $this->recommendOtherCommands($deployment);

            return 0;
        }

        if ($input->getOption('pipe')) {
            $names = array_keys($workers);
            sort($names, SORT_NATURAL);
            $output->writeln($names);

            return 0;
        }

        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');
        $rows = [];
        foreach ($workers as $worker) {
            $commands = isset($worker->worker['commands']) ? $worker->worker['commands'] : [];
            $rows[] = [$worker->name, $worker->type, $formatter->format($commands)];
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Workers on the project <info>%s</info>, environment <info>%s</info>:',
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
        if ($deployment->services) {
            $lines[] = sprintf(
                'To list services, run: <info>%s services</info>',
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
