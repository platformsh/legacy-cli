<?php
namespace Platformsh\Cli\Command\Worker;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class WorkerListCommand extends CommandBase
{
    protected $hiddenInList = true;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('worker:list')
            ->setAliases(['workers'])
            ->setDescription('Get a list of all deployed workers')
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

        $workers = $this->api()
            ->getCurrentDeployment($this->getSelectedEnvironment(), $input->getOption('refresh'))
            ->workers;
        if (empty($workers)) {
            $this->stdErr->writeln('No workers found.');

            return 0;
        }

        $rows = [];
        foreach ($workers as $worker) {
            $rows[] = [$worker->name, $worker->type, json_encode($worker->worker)];
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        $table->render($rows, ['Name', 'Type', 'Worker config']);

        return 0;
    }
}
