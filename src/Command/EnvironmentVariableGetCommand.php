<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Environment;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class EnvironmentVariableGetCommand extends EnvironmentCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('variable:get')
            ->addArgument('name', InputArgument::OPTIONAL, 'The name of the variable')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the full variable value only')
            ->addOption('ssh', null, InputOption::VALUE_NONE, 'Use SSH to get the currently active variables')
            ->addOption('project', null, InputOption::VALUE_OPTIONAL, 'The project ID')
            ->addOption('environment', null, InputOption::VALUE_OPTIONAL, 'The environment ID')
            ->setDescription('Get a variable for an environment.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $environment = new Environment($this->environment);
        $environment->setClient($this->getPlatformClient($this->environment['endpoint']));

        // @todo This --ssh option is only here as a temporary workaround.
        if ($input->getOption('ssh')) {
            $shellHelper = $this->getHelper('shell');
            $platformVariables = $shellHelper->execute(array(
                'ssh',
                $environment->getSshUrl(),
                'echo $PLATFORM_VARIABLES',
              ), true);
            $results = json_decode(base64_decode($platformVariables), true);
            foreach ($results as $id => $value) {
                if (!is_scalar($value)) {
                    $value = json_encode($value);
                }
                $output->writeln("$id\t$value");
            }
            return 0;
        }

        $name = $input->getArgument('name');

        if ($name) {
            $variable = $environment->getVariable($name);
            if (!$variable) {
                $output->writeln("Variable not found: <error>$name</error>");
                return 1;
            }
            $results = array($variable);
        }
        else {
            $results = $environment->getVariables();
            if (!$results) {
                $output->writeln('No variables found');
                return 1;
            }
        }

        if ($input->getOption('pipe') || !$this->isTerminal($output)) {
            foreach ($results as $variable) {
                $output->writeln($variable->id() . "\t" . $variable->getProperty('value'));
            }
        }
        else {
            $table = $this->buildVariablesTable($results, $output);
            $table->render();
        }

        return 0;
    }

    /**
     * @param \CommerceGuys\Platform\Cli\Model\HalResource[] $variables
     * @param OutputInterface $output
     *
     * @return Table
     */
    protected  function buildVariablesTable(array $variables, OutputInterface $output)
    {
        $table = new Table($output);
        $table->setHeaders(array("ID", "Value", "Inherited", "JSON"));
        foreach ($variables as $variable) {
            $value = $variable->getProperty('value');
            // Truncate long values.
            if (strlen($value) > 60) {
                $value = substr($value, 0, 57) . '...';
            }
            // Wrap long values.
            $value = wordwrap($value, 30, "\n", true);
            $table->addRow(array(
                $variable->id(),
                $value,
                $variable->getProperty('inherited') ? 'Yes' : 'No',
                $variable->getProperty('is_json') ? 'Yes' : 'No',
              ));
        }
        return $table;
    }

}
