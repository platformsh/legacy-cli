<?php
namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Variable as EnvironmentLevelVariable;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class VariableGetCommand extends VariableCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('variable:get')
            ->setAliases(['variables'])
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the variable')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the full variable value only')
            ->setDescription('View a variable');
        Table::configureInput($this->getDefinition());
        $this->addProjectOption()
             ->addEnvironmentOption();
        $this->addExample('View the variable "example"', 'example');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, true);

        $name = $input->getArgument('name');

        $variable = $this->getExistingVariable($name);
        if (!$variable) {
            $this->stdErr->writeln("Variable not found: <error>$name</error>");

            return 1;
        }

        if ($input->getOption('pipe')) {
            $output->writeln($variable->value);
        } else {
            $output->writeln(sprintf('<info>%s</info>: %s', $variable->name, $variable->value));
        }

        if ($variable instanceof EnvironmentLevelVariable && !$variable->is_enabled) {
            $this->stdErr->writeln(sprintf(
                "The variable <comment>%s</comment> is disabled.\nEnable it with: <comment>%s variable:enable %s</comment>",
                $variable->name,
                $this->config()->get('application.executable'),
                escapeshellarg($variable->name)
            ));
        }

        return 0;
    }
}
