<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\RuntimeOperation;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Exception\ApiFeatureMissingException;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Exception\OperationUnavailableException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @see \Platformsh\Cli\Command\SourceOperation\ListCommand
 */
#[AsCommand(name: 'operation:list', description: 'List runtime operations on an environment', aliases: ['ops'])]
class ListCommand extends CommandBase
{
    public const COMMAND_MAX_LENGTH = 24;

    /** @var array<string, string> */
    private array $tableHeader = ['service' => 'Service', 'name' => 'Operation name', 'start' => 'Start command', 'stop' => 'Stop command', 'role' => 'Role'];
    /** @var string[] */
    private array $defaultColumns = ['service', 'name', 'start'];
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('full', null, InputOption::VALUE_NONE, 'Do not limit the length of command to display. The default limit is ' . self::COMMAND_MAX_LENGTH . ' lines.');

        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->selector->addAppOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->addOption('worker', null, InputOption::VALUE_REQUIRED, 'A worker name');

        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(chooseEnvFilter: SelectorConfig::filterEnvsMaybeActive()));
        $deployment = $this->api->getCurrentDeployment($selection->getEnvironment());

        // Fetch a list of operations grouped by service name, either for one
        // service or all of the services in an environment.
        try {
            if ($input->getOption('app') || $input->getOption('worker')) {
                $selectedApp = $selection->getRemoteContainer();
                $operations = [
                    $selectedApp->getName() => $selectedApp->getRuntimeOperations(),
                ];
            } else {
                $selectedApp = null;
                $operations = $deployment->getRuntimeOperations();
            }
        } catch (OperationUnavailableException) {
            throw new ApiFeatureMissingException('This project does not support runtime operations.');
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

        if (!count($rows)) {
            $this->stdErr->writeln('No runtime operations found.');

            $this->stdErr->writeln('');
            $this->stdErr->writeln("Runtime operations can be configured in the application's YAML definition.");

            if ($this->config->has('service.runtime_operations_help_url')) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('For more information see: ' . $this->config->getStr('service.runtime_operations_help_url'));
            }

            return 0;
        }

        if (!$this->table->formatIsMachineReadable()) {
            if ($selectedApp !== null) {
                $this->stdErr->writeln(sprintf(
                    'Runtime operations on the environment %s, app <info>%s</info>:',
                    $this->api->getEnvironmentLabel($selection->getEnvironment()),
                    $selectedApp->getName(),
                ));
            } else {
                $this->stdErr->writeln(sprintf(
                    'Runtime operations on the environment %s:',
                    $this->api->getEnvironmentLabel($selection->getEnvironment()),
                ));
            }
        }

        $this->table->render($rows, $this->tableHeader, $this->defaultColumns);

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(\sprintf('To run an operation, use: <info>%s operation:run [operation]</info>', $this->config->getStr('application.executable')));
        }

        return 0;
    }

    private function truncateCommand(string $cmd): string
    {
        $lines = (array) \preg_split('/\r?\n/', $cmd);
        if (count($lines) > self::COMMAND_MAX_LENGTH) {
            return trim(implode("\n", array_slice($lines, 0, self::COMMAND_MAX_LENGTH))) . "\n# ...";
        }
        return trim($cmd);
    }
}
