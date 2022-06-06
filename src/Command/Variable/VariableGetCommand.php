<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\Selection;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\SubCommandRunner;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Service\VariableService;
use Platformsh\Client\Model\ProjectLevelVariable;
use Platformsh\Client\Model\Variable as EnvironmentLevelVariable;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class VariableGetCommand extends CommandBase
{
    protected static $defaultName = 'variable:get|vget';
    protected static $defaultDescription = 'View a variable';

    private $config;
    private $formatter;
    private $questionHelper;
    private $selector;
    private $subCommandRunner;
    private $table;
    private $variableService;

    public function __construct(
        Config $config,
        PropertyFormatter $formatter,
        QuestionHelper $questionHelper,
        Selector $selector,
        Table $table,
        SubCommandRunner $subCommandRunner,
        VariableService $variableService
    ) {
        $this->config = $config;
        $this->formatter = $formatter;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        $this->subCommandRunner = $subCommandRunner;
        $this->table = $table;
        $this->variableService = $variableService;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'The name of the variable')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'View a single variable property');
        $this->variableService->addLevelOption($this->getDefinition());
        $this->table->configureInput($this->getDefinition());
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addExample('View the variable "example"', 'example');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $level = $this->variableService->getRequestedLevel($input);
        $selection = $this->selector->getSelection($input, $level === VariableService::LEVEL_PROJECT);

        $name = $input->getArgument('name');
        if ($name) {
            $variable = $this->variableService->getExistingVariable($name, $level);
            if (!$variable) {
                return 1;
            }
        } elseif ($input->isInteractive()) {
            $variable = $this->chooseVariable($level, $selection);
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
                $this->config->get('application.executable'),
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
        $properties['level'] = $this->variableService->getVariableLevel($variable);

        if ($property = $input->getOption('property')) {
            if ($property === 'value' && !isset($properties['value']) && $variable->is_sensitive) {
                $this->stdErr->writeln('The variable is sensitive, so its value cannot be read.');
                return 1;
            }

            $this->formatter->displayData($output, $properties, $property);

            return 0;
        }

        $this->variableService->displayVariable($variable);

        if (!$this->table->formatIsMachineReadable()) {
            $executable = $this->config->get('application.executable');
            $escapedName = $this->variableService->escapeShellArg($name);
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
     * @param Selection $selection
     *
     * @return ProjectLevelVariable|EnvironmentLevelVariable|false
     */
    private function chooseVariable($level, Selection $selection) {
        $variables = [];
        if ($level === 'project' || $level === null) {
            $variables = array_merge($variables, $selection->getProject()->getVariables());
        }
        if ($level === 'environment' || $level === null) {
            $variables = array_merge($variables, $selection->getEnvironment()->getVariables());
        }
        if (empty($variables)) {
            return false;
        }
        $options = [];
        foreach ($variables as $key => $variable) {
            $options[$key] = $variable->name;
        }
        asort($options);
        $key = $this->questionHelper->choose($options, 'Enter a number to choose a variable:');

        return $variables[$key];
    }
}
