<?php
namespace Platformsh\Cli\Command\Service;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ServiceListCommand extends CommandBase
{
    protected static $defaultName = 'service:list';

    private $api;
    private $config;
    private $selector;
    private $table;

    public function __construct(
        Api $api,
        Config $config,
        Selector $selector,
        Table $table
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->selector = $selector;
        $this->table = $table;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setAliases(['services'])
            ->setHidden(true)
            ->setDescription('List services in the project')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->table->configureInput($definition);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);

        // Find a list of deployed services.
        $deployment = $this->api
            ->getCurrentDeployment($selection->getEnvironment(), $input->getOption('refresh'));
        $services = $deployment->services;

        if (!count($services)) {
            $this->stdErr->writeln('No services found.');

            if ($deployment->webapps) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(sprintf(
                    'To list applications, run: <info>%s apps</info>',
                    $this->config->get('application.executable')
                ));
            }

            return 0;
        }

        $headers = ['Name', 'Type', 'Disk (MiB)', 'Size'];

        $rows = [];
        foreach ($services as $name => $service) {
            $row = [
                $name,
                $service->type,
                $service->disk !== null ? $service->disk : '',
                $service->size,
            ];
            $rows[] = $row;
        }

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Services on the project <info>%s</info>, environment <info>%s</info>:',
                $this->api->getProjectLabel($selection->getProject()),
                $this->api->getEnvironmentLabel($selection->getEnvironment())
            ));
        }

        $this->table->render($rows, $headers);

        if (!$this->table->formatIsMachineReadable() && $deployment->webapps) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To list applications, run: <info>%s apps</info>',
                $this->config->get('application.executable')
            ));
        }

        return 0;
    }
}
