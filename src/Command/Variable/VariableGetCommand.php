<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Selector\Selection;
use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\SubCommandRunner;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Service\VariableCommandUtil;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Client\Model\ProjectLevelVariable;
use Platformsh\Client\Model\Variable as EnvironmentLevelVariable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'variable:get', description: 'View a variable', aliases: ['vget'])]
class VariableGetCommand extends CommandBase
{
    public function __construct(
        private readonly Config              $config,
        private readonly Io                  $io,
        private readonly QuestionHelper      $questionHelper,
        private readonly PropertyFormatter   $propertyFormatter,
        private readonly Selector            $selector,
        private readonly SubCommandRunner    $subCommandRunner,
        private readonly Table               $table,
        private readonly VariableCommandUtil $variableCommandUtil,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'The name of the variable')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'View a single variable property');
        $this->variableCommandUtil->addLevelOption($this->getDefinition());
        Table::configureInput($this->getDefinition());
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->addOption('pipe', null, InputOption::VALUE_NONE, '[Deprecated option] Output the variable value only');
        $this->addExample('View the variable "example"', 'example');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io->warnAboutDeprecatedOptions(['pipe']);
        $level = $this->variableCommandUtil->getRequestedLevel($input);
        $selection = $this->selector->getSelection($input, new SelectorConfig(envRequired: $level !== VariableCommandUtil::LEVEL_PROJECT));

        $name = $input->getArgument('name');
        if ($name) {
            $variable = $this->variableCommandUtil->getExistingVariable($name, $selection, $level);
            if (!$variable) {
                return 1;
            }
        } elseif ($input->isInteractive()) {
            $variable = $this->chooseVariable($selection, $level);
            if (!$variable) {
                $this->stdErr->writeln('No variables found');
                return 1;
            }
        } else {
            return $this->subCommandRunner->run('variable:list', array_filter([
                '--level' => $level,
                '--project' => $selection->getProject()->id,
                '--environment' => $selection->hasEnvironment() ? $selection->getEnvironment()->id : null,
                '--format' => $input->getOption('format'),
            ]));
        }

        if ($variable instanceof EnvironmentLevelVariable && !$variable->is_enabled) {
            $this->stdErr->writeln(sprintf(
                "The variable <comment>%s</comment> is disabled.\nEnable it with: <comment>%s variable:enable %s</comment>",
                $variable->name,
                $this->config->getStr('application.executable'),
                escapeshellarg($variable->name),
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
        $properties['level'] = $this->variableCommandUtil->getVariableLevel($variable);

        if ($property = $input->getOption('property')) {
            if ($property === 'value' && !isset($properties['value']) && $variable->is_sensitive) {
                $this->stdErr->writeln('The variable is sensitive, so its value cannot be read.');
                return 1;
            }

            $formatter = $this->propertyFormatter;
            $formatter->displayData($output, $properties, $property);

            return 0;
        }

        $this->variableCommandUtil->displayVariable($variable);

        if (!$this->table->formatIsMachineReadable()) {
            $executable = $this->config->getStr('application.executable');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To list other variables, run: <info>%s variables</info>',
                $executable,
            ));
            $this->stdErr->writeln(sprintf(
                'To update the variable, use: <info>%s variable:update %s</info>',
                $executable,
                OsUtil::escapeShellArg($name),
            ));
        }

        return 0;
    }

    private function chooseVariable(Selection $selection, ?string $level): ProjectLevelVariable|EnvironmentLevelVariable|false
    {
        $projectVariables = [];
        if ($level === 'project' || $level === null) {
            foreach ($selection->getProject()->getVariables() as $variable) {
                $projectVariables[$variable->name] = $variable;
            }
        }
        $environmentVariables = [];
        if ($level === 'environment' || $level === null) {
            foreach ($selection->getEnvironment()->getVariables() as $variable) {
                $environmentVariables[$variable->name] = $variable;
            }
        }
        if (empty($environmentVariables) && empty($projectVariables)) {
            return false;
        }
        $projectPrefix = '__PROJECT__:';
        $options = array_combine(array_keys($environmentVariables), array_keys($environmentVariables));
        foreach ($projectVariables as $name => $variable) {
            $options[$projectPrefix . $name] = $name
                . (isset($options[$name]) ? ' (project-level)' : '');
        }
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);
        $key = $this->questionHelper->choose($options, 'Enter a number to choose a variable:');
        if (str_starts_with($key, $projectPrefix)) {
            return $projectVariables[substr($key, strlen($projectPrefix))];
        }

        return $environmentVariables[$key];
    }
}
