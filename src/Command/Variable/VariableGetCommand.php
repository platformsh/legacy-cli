<?php
namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\PropertyFormatter;
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
            ->setAliases(['vget'])
            ->addArgument('name', InputArgument::OPTIONAL, 'The name of the variable')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'View a single variable property')
            ->setDescription('View a variable');
        Table::configureInput($this->getDefinition());
        PropertyFormatter::configureInput($this->getDefinition());
        $this->addProjectOption()
             ->addEnvironmentOption();
        $this->addOption('pipe', null, InputOption::VALUE_NONE, '[Deprecated option] Output the variable value only');
        $this->addExample('View the variable "example"', 'example');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, true);
        $this->warnAboutDeprecatedOptions(['pipe']);

        $name = $input->getArgument('name');
        if (!$name) {
            $executable = $this->config()->get('application.executable');
            $this->stdErr->writeln('To list variables, use: <comment>' . $executable . ' variable:list</comment>');

            return $this->runOtherCommand('variable:list', array_filter([
                '--project' => $input->getOption('project'),
                '--environment' => $input->getOption('environment'),
                '--format' => $input->getOption('format'),
            ]));
        }

        $variable = $this->getExistingVariable($name);
        if (!$variable) {
            $this->stdErr->writeln("Variable not found: <error>$name</error>");

            return 1;
        }

        if ($variable instanceof EnvironmentLevelVariable && !$variable->is_enabled) {
            $this->stdErr->writeln(sprintf(
                "The variable <comment>%s</comment> is disabled.\nEnable it with: <comment>%s variable:enable %s</comment>",
                $variable->name,
                $this->config()->get('application.executable'),
                escapeshellarg($variable->name)
            ));
        }

        if ($input->getOption('pipe')) {
            $output->writeln($variable->value);

            return 0;
        }

        $properties = $variable->getProperties();
        $properties['level'] = $this->getVariableLevel($variable);

        if ($property = $input->getOption('property')) {
            /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
            $formatter = $this->getService('property_formatter');
            $formatter->displayData($output, $properties, $property);

            return 0;
        }

        $this->displayVariable($variable);

        return 0;
    }
}
