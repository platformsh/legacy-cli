<?php

namespace Platformsh\Cli\Command\Variable;

use Platformsh\ConsoleForm\Form;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VariableUpdateCommand extends VariableCommandBase
{
    /** @var Form */
    private $form;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('variable:update')
            ->setDescription('Update a variable')
            ->addArgument('name', InputArgument::REQUIRED, 'The variable name');
        $fields = $this->getFields();
        unset($fields['name'], $fields['prefix'], $fields['environment'], $fields['level']);
        $this->form = Form::fromArray($fields);
        $this->form->configureInputDefinition($this->getDefinition());
        $this->addProjectOption()
            ->addEnvironmentOption()
            ->addWaitOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, true);

        $name = $input->getArgument('name');
        $variable = $this->getExistingVariable($name);
        if (!$variable) {
            $this->stdErr->writeln(sprintf('Variable not found: <error>%s</error>', $name));

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

        if (!$values) {
            $this->stdErr->writeln('No changes were provided.');

            return 1;
        }

        $result = $variable->update($values);
        $this->stdErr->writeln("Variable <info>{$variable->name}</info> updated");

        $this->displayVariable($variable);

        $success = true;
        if (!$result->countActivities()) {
            $this->redeployWarning();
        } elseif ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $success = $activityMonitor->waitMultiple($result->getActivities(), $this->getSelectedProject());
        }

        return $success ? 0 : 1;
    }
}
