<?php
namespace Platformsh\Cli\Command\Service;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Deployment\EnvironmentDeployment;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ServiceListCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('service:list')
            ->setAliases(['services'])
            ->setDescription('List services in the project')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');
        $this->addProjectOption()
            ->addEnvironmentOption();
        Table::configureInput($this->getDefinition());
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
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

        $headers = ['Name', 'Type', 'disk' => 'Disk (MiB)', 'Size'];

        $rows = [];
        foreach ($services as $name => $service) {
            $row = [
                $name,
                $service->type,
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

        $table->render($rows, $headers);

        if (!$table->formatIsMachineReadable()) {
            $this->recommendOtherCommands($deployment);
        }

        return 0;
    }

    private function recommendOtherCommands(EnvironmentDeployment $deployment)
    {
        if ($deployment->webapps || $deployment->workers) {
            $this->stdErr->writeln('');
        }
        if ($deployment->webapps) {
            $this->stdErr->writeln(sprintf(
                'To list applications, run: <info>%s apps</info>',
                $this->config()->get('application.executable')
            ));
        }
        if ($deployment->workers) {
            $this->stdErr->writeln(sprintf(
                'To list workers, run: <info>%s workers</info>',
                $this->config()->get('application.executable')
            ));
        }
    }
}
