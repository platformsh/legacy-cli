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
            ->addOption('search', null, InputOption::VALUE_NONE, 'Search for variables')
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

        // This is only here as a workaround, because Platform.sh does not
        // currently rebuild environments when variables are changed.
        if ($input->getOption('ssh')) {
            $shellHelper = $this->getHelper('shell');
            $platformVariables = $shellHelper->executeArgs(array('ssh', $environment->getSshUrl(), 'echo $PLATFORM_VARIABLES'), true);
            $results = json_decode(base64_decode($platformVariables), true);
            $table = new Table($output);
            $table->setHeaders(array("ID", "Value"));
            foreach ($results as $key => $id) {
                $table->addRow(array($key, $id));
            }
            $table->render();
            return 0;
        }

        $name = $input->getArgument('name');
        $search = $input->getOption('search');

        if ($name && !$search) {
            $variable = $environment->getVariable($name);
            if (!$variable) {
                $output->writeln("Variable not found: <error>$name</error>");
                return 1;
            }

            /** @var \CommerceGuys\Platform\Cli\Model\Resource $variable */

            if ($input->getOption('pipe') || !$this->isTerminal($output)) {
                $output->write($variable->getProperty('value'));
                return 0;
            }
            $results = array($variable);
        }
        else {
            $results = $environment->getVariables($name);
        }

        if (!$results) {
            $error = "No variables found";
            if ($name) {
                $error .= " for <error>$name</error>";
            }
            $output->writeln($error);
            return 1;
        }

        $table = new Table($output);
        $table->setHeaders(array("ID", "Value", "Inherited", "JSON"));
        foreach ($results as $variable) {
            $value = $variable->getProperty('value');
            if (strlen($value) > 43) {
                $value = substr($value, 0, 40) . '...';
            }
            $table->addRow(array(
                $variable->getId(),
                $value,
                $variable->getProperty('inherited') ? 'Yes' : 'No',
                $variable->getProperty('is_json') ? 'Yes' : 'No',
              ));
        }
        $table->render();
        return 0;
    }

}
