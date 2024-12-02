<?php

namespace Platformsh\Cli\Command\SourceOperation;

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

#[AsCommand(name: 'source-operation:list', description: 'List source operations on an environment', aliases: ['source-ops'])]
class ListCommand extends CommandBase
{
    const COMMAND_MAX_LENGTH = 24;

    private array $tableHeader = ['Operation', 'App', 'Command'];
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->addOption('full', null, InputOption::VALUE_NONE, 'Do not limit the length of command to display. The default limit is ' . self::COMMAND_MAX_LENGTH . ' lines.');

        $this->addProjectOption();
        $this->addEnvironmentOption();

        Table::configureInput($this->getDefinition(), $this->tableHeader);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateInput($input);

        try {
            $sourceOps = $this->getSelectedEnvironment()->getSourceOperations();
        } catch (OperationUnavailableException) {
            throw new ApiFeatureMissingException('This project does not support source operations.');
        }

        if (!count($sourceOps)) {
            $this->stdErr->writeln('No source operations found.');

            return 0;
        }

        $rows = [];
        foreach ($sourceOps as $sourceOp) {
            $row = [];
            $row[] = new AdaptiveTableCell($sourceOp->operation, ['wrap' => false]);
            $row[] = $sourceOp->app;
            $row[] = $input->getOption('full') ? $sourceOp->command : $this->truncateCommand($sourceOp->command);
            $rows[] = $row;
        }

        $table = $this->table;

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Source operations on the project <info>%s</info>, environment <info>%s</info>:',
                $this->api->getProjectLabel($this->getSelectedProject()),
                $this->api->getEnvironmentLabel($this->getSelectedEnvironment())
            ));
        }

        $table->render($rows, $this->tableHeader);

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln(\sprintf('To run a source operation, use: <info>%s source-operation:run [operation]</info>', $this->config->get('application.executable')));
        }

        return 0;
    }

    private function truncateCommand($cmd): string
    {
        $lines = \preg_split('/\r?\n/', (string) $cmd);
        if (count($lines) > self::COMMAND_MAX_LENGTH) {
            return trim(implode("\n", array_slice($lines, 0, self::COMMAND_MAX_LENGTH))) . "\n# ...";
        }
        return trim((string) $cmd);
    }
}
