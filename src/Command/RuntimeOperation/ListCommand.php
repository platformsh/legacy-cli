<?php

namespace Platformsh\Cli\Command\RuntimeOperation;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Exception\ApiFeatureMissingException;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Exception\OperationUnavailableException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @see \Platformsh\Cli\Command\SourceOperation\ListCommand
 */
class ListCommand extends CommandBase
{
    const COMMAND_MAX_LENGTH = 24;

    protected $stability = self::STABILITY_BETA;

    private $tableHeader = ['service' => 'Service', 'name' => 'Operation name', 'start' => 'Start command', 'stop' => 'Stop command', 'role' => 'Role'];
    private $defaultColumns = ['service', 'name', 'start'];

    protected function configure()
    {
        $this->setName('operation:list')
            ->setAliases(['ops'])
            ->setDescription('List runtime operations on an environment')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Do not limit the length of command to display. The default limit is ' . self::COMMAND_MAX_LENGTH . ' lines.');

        $this->addProjectOption();
        $this->addEnvironmentOption();
        $this->addAppOption();
        $this->addOption('worker', null, InputOption::VALUE_REQUIRED, 'A worker name');

        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $deployment = $this->api()->getCurrentDeployment($this->getSelectedEnvironment());

        try {
            if ($input->getOption('app') || $input->getOption('worker')) {
                $selectedApp = $this->selectRemoteContainer($input);
                $operations = [
                    $selectedApp->getName() => $selectedApp->getRuntimeOperations(),
                ];
            } else {
                $selectedApp = null;
                $operations = $deployment->getRuntimeOperations();
            }
        } catch (OperationUnavailableException $e) {
            throw new ApiFeatureMissingException('This project does not support runtime operations.');
        }

        if (!count($operations)) {
            $this->stdErr->writeln('No runtime operations found.');

            // @todo link to help
            $this->stdErr->writeln('');
            $this->stdErr->writeln("Runtime operations can be configured in the application's YAML definition.");

            return 0;
        }

        $rows = [];
        foreach ($operations as $serviceName => $appOperations) {
            foreach ($appOperations as $name => $op) {
                $row = [];
                $row['service'] = $serviceName;
                $row['name'] = new AdaptiveTableCell($name, ['wrap' => false]);
                $row['start'] = $input->getOption('full') ? $op->commands['start'] : $this->truncateCommand($op->commands['start']);
                $row['stop'] = $input->getOption('full') ? $op->commands['stop'] : $this->truncateCommand($op->commands['stop']);
                $row['role'] = $op->role;
                $rows[] = $row;
            }
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');

        if (!$table->formatIsMachineReadable()) {
            if ($selectedApp !== null) {
                $this->stdErr->writeln(sprintf(
                    'Runtime operations on the environment %s, app <info>%s</info>:',
                    $this->api()->getEnvironmentLabel($this->getSelectedEnvironment()),
                    $selectedApp->getName()
                ));
            } else {
                $this->stdErr->writeln(sprintf(
                    'Runtime operations on the environment %s:',
                    $this->api()->getEnvironmentLabel($this->getSelectedEnvironment()),
                ));
            }
        }

        $table->render($rows, $this->tableHeader, $this->defaultColumns);

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(\sprintf('To run an operation, use: <info>%s operation:run [operation]</info>', $this->config()->get('application.executable')));
        }

        return 0;
    }

    private function truncateCommand($cmd)
    {
        $lines = \preg_split('/\r?\n/', $cmd);
        if (count($lines) > self::COMMAND_MAX_LENGTH) {
            return trim(implode("\n", array_slice($lines, 0, self::COMMAND_MAX_LENGTH))) . "\n# ...";
        }
        return trim($cmd);
    }
}
