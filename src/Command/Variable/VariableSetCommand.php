<?php
namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @deprecated Use variable:create and variable:update instead (with --level environment)
 */
#[AsCommand(name: 'variable:set', description: 'Set a variable for an environment', aliases: ['vset'])]
class VariableSetCommand extends CommandBase
{
    protected bool $hiddenInList = true;
    protected string $stability = 'deprecated';
    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Api $api, private readonly Selector $selector)
    {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The variable name')
            ->addArgument('value', InputArgument::REQUIRED, 'The variable value')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Mark the value as JSON')
            ->addOption('disabled', null, InputOption::VALUE_NONE, 'Mark the variable as disabled');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->activityMonitor->addWaitOptions($this->getDefinition());
        $this->setHelp(
            'This command is deprecated and will be removed in a future version.'
            . "\nInstead, use <info>variable:create</info> and <info>variable:update</info>"
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);

        $variableName = $input->getArgument('name');
        $variableValue = $input->getArgument('value');
        $json = $input->getOption('json');
        $enabled = !$input->getOption('disabled');

        if ($json && !$this->validateJson($variableValue)) {
            throw new InvalidArgumentException("Invalid JSON: <error>$variableValue</error>");
        }

        // Check whether the variable already exists. If there is no change,
        // quit early.
        $existing = $selection->getEnvironment()
                         ->getVariable($variableName);
        if ($existing
            && $existing->value === $variableValue
            && $existing->is_enabled === $enabled
            && $existing->is_json == $json) {
            $this->stdErr->writeln("Variable <info>$variableName</info> already set as: $variableValue");

            return 0;
        }

        // Set the variable to a new value.
        $result = $selection->getEnvironment()
                       ->setVariable($variableName, $variableValue, $json, $enabled);

        $this->stdErr->writeln("Variable <info>$variableName</info> set to: $variableValue");

        $success = true;
        if (!$result->countActivities()) {
            $this->api->redeployWarning();
        } elseif ($this->shouldWait($input)) {
            $activityMonitor = $this->activityMonitor;
            $success = $activityMonitor->waitMultiple($result->getActivities(), $selection->getProject());
        }

        return $success ? 0 : 1;
    }

    /**
     * @param $string
     *
     * @return bool
     */
    protected function validateJson($string)
    {
        if ($string === 'null') {
            return true;
        }
        return \json_decode((string) $string) !== null;
    }
}
