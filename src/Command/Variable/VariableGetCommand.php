<?php
namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class VariableGetCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('variable:get')
            ->setAliases(['variables', 'vget'])
            ->addArgument('name', InputArgument::OPTIONAL, 'The name of the variable')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the full variable value only (a "name" must be specified)')
            ->setDescription('View variable(s) for an environment');
        Table::configureInput($this->getDefinition());
        $this->addProjectOption()
             ->addEnvironmentOption();
        $this->addExample('View the variable "example"', 'example');
        $this->setHiddenAliases(['variable:list']);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        if ($name = $input->getArgument('name')) {
            $variable = $this->getSelectedEnvironment()
                             ->getVariable($name);
            if (!$variable) {
                $this->stdErr->writeln("Variable not found: <error>$name</error>");

                return 1;
            }

            if ($input->getOption('pipe')) {
                $output->writeln($variable->value);
            } else {
                $output->writeln(sprintf('<info>%s</info>: %s', $variable->name, $variable->value));
            }

            return 0;
        }

        $results = $this->getSelectedEnvironment()
                        ->getVariables();
        if (!$results) {
            $this->stdErr->writeln('No variables found');

            return 1;
        }

        if ($input->getOption('pipe')) {
            throw new InvalidArgumentException('Specify a variable name to use --pipe');
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');

        $header = ['ID', 'Value', 'Inherited', 'JSON'];
        $rows = [];
        foreach ($results as $variable) {
            $rows[] = [
                new AdaptiveTableCell($variable->id, ['wrap' => false]),
                $variable->value,
                $variable->inherited ? 'Yes' : 'No',
                $variable->is_json ? 'Yes' : 'No',
            ];
        }

        $table->render($rows, $header);

        return 0;
    }
}
