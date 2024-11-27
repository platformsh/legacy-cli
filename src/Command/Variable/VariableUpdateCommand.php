<?php

namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\ConsoleForm\Form;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'variable:update', description: 'Update a variable')]
class VariableUpdateCommand extends VariableCommandBase
{
    /** @var Form */
    private $form;
    public function __construct(private readonly ActivityMonitor $activityMonitor)
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
            ->addOption('allow-no-change', null, InputOption::VALUE_NONE, 'Return success (zero exit code) if no changes were provided');
        $this->addLevelOption();
        $fields = $this->getFields();
        unset($fields['name'], $fields['prefix'], $fields['environment'], $fields['level']);
        $this->form = Form::fromArray($fields);
        $this->form->configureInputDefinition($this->getDefinition());
        $this->addProjectOption()
            ->addEnvironmentOption()
            ->addWaitOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $level = $this->getRequestedLevel($input);
        $this->validateInput($input, $level === self::LEVEL_PROJECT);

        $name = $input->getArgument('name');
        $variable = $this->getExistingVariable($name, $level);
        if (!$variable) {
            return 1;
        }

        $values = [];
        $fields = $this->form->getFields();
        foreach ($variable->getProperties() as $property => $value) {
            if (isset($fields[$property])) {
                $newValue = $fields[$property]->getValueFromInput($input);
                if ($newValue !== null && $newValue !== $value) {
                    $values[$property] = $newValue;
                }
            }
        }

        // Handle sensitive variables' value (it isn't exposed in the API).
        if (!$variable->hasProperty('value') && $variable->is_sensitive) {
            $newValue = $fields['value']->getValueFromInput($input);
            if ($newValue !== null) {
                $values['value'] = $newValue;
            }
        }

        // Validate the is_json setting against the value.
        if ((isset($variable->value) || isset($values['value']))
            && (!empty($values['is_json']) || $variable->is_json)) {
            $value = isset($values['value']) ? $values['value'] : $variable->value;
            if (json_decode($value) === null && json_last_error()) {
                $this->stdErr->writeln('The value is not valid JSON: <error>' . $value . '</error>');

                return 1;
            }
        }

        if (!$values) {
            $this->stdErr->writeln('No changes were provided.');

            return $input->getOption('allow-no-change') ? 0 : 1;
        }

        $result = $variable->update($values);
        $this->stdErr->writeln("Variable <info>{$variable->name}</info> updated");

        $this->displayVariable($variable);

        $success = true;

        if (!$result->countActivities() || $level === self::LEVEL_PROJECT) {
            $this->redeployWarning();
        } elseif ($this->shouldWait($input)) {
            $activityMonitor = $this->activityMonitor;
            $success = $activityMonitor->waitMultiple($result->getActivities(), $this->getSelectedProject());
        }

        return $success ? 0 : 1;
    }
}
