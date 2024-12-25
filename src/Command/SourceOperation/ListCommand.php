<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\SourceOperation;

use Platformsh\Cli\Selector\Selector;
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
    public const COMMAND_MAX_LENGTH = 24;

    /** @var array<string|int, string> */
    private array $tableHeader = ['Operation', 'App', 'Command'];

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
        $this->addCompleter($this->selector);

        Table::configureInput($this->getDefinition(), $this->tableHeader);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);

        try {
            $sourceOps = $selection->getEnvironment()->getSourceOperations();
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

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Source operations on the project <info>%s</info>, environment <info>%s</info>:',
                $this->api->getProjectLabel($selection->getProject()),
                $this->api->getEnvironmentLabel($selection->getEnvironment()),
            ));
        }

        $this->table->render($rows, $this->tableHeader);

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(\sprintf('To run a source operation, use: <info>%s source-operation:run [operation]</info>', $this->config->getStr('application.executable')));
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
