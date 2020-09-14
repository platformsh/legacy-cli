<?php
namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\ProjectLevelVariable;
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
        $this->addProjectOption()
             ->addEnvironmentOption();
        $this->addOption('pipe', null, InputOption::VALUE_NONE, '[Deprecated option] Output the variable value only');
        $this->addExample('View the variable "example"', 'example');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->warnAboutDeprecatedOptions(['pipe']);
        $level = $this->getRequestedLevel($input);
        $this->validateInput($input, $level === self::LEVEL_PROJECT);

        $name = $input->getArgument('name');
        if ($name) {
            $variable = $this->getExistingVariable($name, $level);
            if (!$variable) {
                return 1;
            }
        } elseif ($input->isInteractive()) {
                $variable = $this->chooseVariable($level);
        } else {
            return $this->runOtherCommand('variable:list', array_filter([
                '--level' => $level,
                '--project' => $this->getSelectedProject()->id,
                '--environment' => $this->hasSelectedEnvironment() ? $this->getSelectedEnvironment()->id : null,
                '--format' => $input->getOption('format'),
            ]));
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
            if (!$variable->hasProperty('value')) {
                if ($variable->is_sensitive) {
                    $this->stdErr->writeln('The variable is sensitive, so its value cannot be read.');
                } else {
                    $this->stdErr->writeln('No variable value found.');
                }
                return 1;
            }
            $output->writeln($variable->value);

            return 0;
        }

        $properties = $variable->getProperties();
        $properties['level'] = $this->getVariableLevel($variable);

        if ($property = $input->getOption('property')) {
            if ($property === 'value' && !isset($properties['value']) && $variable->is_sensitive) {
                $this->stdErr->writeln('The variable is sensitive, so its value cannot be read.');
                return 1;
            }

            /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
            $formatter = $this->getService('property_formatter');
            $formatter->displayData($output, $properties, $property);

            return 0;
        }

        $this->displayVariable($variable);

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');

        if (!$table->formatIsMachineReadable()) {
            $executable = $this->config()->get('application.executable');
            $escapedName = $this->escapeShellArg($name);
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To list other variables, run: <info>%s variables</info>',
                $executable
            ));
            $this->stdErr->writeln(sprintf(
                'To update the variable, use: <info>%s variable:update %s</info>',
                $executable,
                $escapedName
            ));
        }

        return 0;
    }

    /**
     * @param string|null $level
     *
     * @return ProjectLevelVariable|EnvironmentLevelVariable
     */
    private function chooseVariable($level) {
        $variables = [];
        if ($level === 'project' || $level === null) {
            $variables = array_merge($variables, $this->getSelectedProject()->getVariables());
        }
        if ($level === 'environment' || $level === null) {
            $variables = array_merge($variables, $this->getSelectedEnvironment()->getVariables());
        }
        $options = [];
        foreach ($variables as $key => $variable) {
            $options[$key] = $variable->name;
        }
        asort($options);
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $key = $questionHelper->choose($options, 'Enter a number to choose a variable:');

        return $variables[$key];
    }
}
