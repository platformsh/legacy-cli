<?php
namespace Platformsh\Cli\Command\Variable;

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
        $this->addLevelOption();
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
        $level = $this->getRequestedLevel($input);

        $name = $input->getArgument('name');
        if (!$name) {
            return $this->runOtherCommand('variable:list', array_filter([
                '--project' => $this->getSelectedProject()->id,
                '--environment' => $this->hasSelectedEnvironment() ? $this->getSelectedEnvironment()->id : null,
                '--format' => $input->getOption('format'),
            ]));
        }

        $variable = $this->getExistingVariable($name, $level);
        if (!$variable) {
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
