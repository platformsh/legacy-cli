<?php

namespace Platformsh\Cli\Command\SourceOperation;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\ApiFeatureMissingException;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Exception\OperationUnavailableException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends CommandBase
{
    private $tableHeader = ['Operation', 'App', 'Command'];

    protected function configure()
    {
        $this->setName('source-operation:list')
            ->setAliases(['source-ops'])
            ->setDescription('List source operations on an environment');

        $this->addProjectOption();
        $this->addEnvironmentOption();

        Table::configureInput($this->getDefinition(), $this->tableHeader);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        try {
            $sourceOps = $this->getSelectedEnvironment()->getSourceOperations();
        } catch (OperationUnavailableException $e) {
            throw new ApiFeatureMissingException('This project does not support source operations.');
        }

        if (!count($sourceOps)) {
            $this->stdErr->writeln('No source operations found.');

            return 0;
        }

        $rows = [];
        foreach ($sourceOps as $sourceOp) {
            $rows[] = [
                $sourceOp->operation,
                $sourceOp->app,
                $sourceOp->command,
            ];
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Source operations on the project <info>%s</info>, environment <info>%s</info>:',
                $this->api()->getProjectLabel($this->getSelectedProject()),
                $this->api()->getEnvironmentLabel($this->getSelectedEnvironment())
            ));
        }

        $table->render($rows, $this->tableHeader);

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln(\sprintf('To run a source operation, use: <info>%s source-operation:run [operation]</info>', $this->config()->get('application.executable')));
        }

        return 0;
    }
}
