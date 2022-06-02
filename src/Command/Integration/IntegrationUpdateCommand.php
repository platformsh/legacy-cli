<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Integration;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\ConsoleForm\Exception\ConditionalFieldException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationUpdateCommand extends IntegrationCommandBase
{
    protected static $defaultName = 'integration:update';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'The ID of the integration to update')
            ->setDescription('Update an integration');

        $definition = $this->getDefinition();
        $form = $this->getForm();
        $form->getField('type')->set('includeAsOption', false);
        $form->configureInputDefinition($definition);
        $this->selector->addProjectOption($definition);
        $this->activityService->configureInput($definition);

        $this->addExample(
            'Switch on the "fetch branches" option for a specific integration',
            'ZXhhbXBsZSB --fetch-branches 1'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);
        $project = $selection->getProject();

        $integration = $this->selectIntegration($project, $input->getArgument('id'), $input->isInteractive());
        if (!$integration) {
            return 1;
        }

        $form = $this->getForm();

        // Resolve options, only for one type.
        $values = $integration->getProperties();
        $newValues = [];
        foreach ($form->getFields() as $key => $field) {
            if ($key === 'type') {
                continue;
            }
            $field->onChange($values);
            if (!$form->includeField($field, $values)) {
                if ($field->getValueFromInput($input, false) !== null) {
                    return $this->handleConditionalFieldException(new ConditionalFieldException('--' . $field->getOptionName() . ' is not applicable', $field, $values));
                }
                continue;
            }
            $value = $field->getValueFromInput($input, false);
            $parents = $field->getValueKeys() ?: [$key];
            if ($value !== null) {
                $field->validate($value);
                $value = $field->getFinalValue($value);
                $form::setNestedArrayValue($newValues, $parents, $value, true);
            }
        }

        $this->postProcessValues($newValues, $integration);

        // Check if anything changed.
        foreach ($integration->getProperties() as $key => $currentValue) {
            if (isset($newValues[$key])) {
                // Remove any new values that are the same as the current value.
                if ($this->valuesAreEqual($currentValue, $newValues[$key])) {
                    unset($newValues[$key]);
                }
            }
        }

        if (!$newValues) {
            $this->stdErr->writeln('No changed values were provided to update.');

            $this->ensureHooks($integration, [], $project);
            $this->stdErr->writeln('');
            $this->displayIntegration($integration);

            return 1;
        }

        try {
            $result = $integration->update($newValues);
        } catch (BadResponseException $e) {
            $errors = $integration->listValidationErrors($e);
            if ($errors) {
                $this->stdErr->writeln(sprintf(
                    'The integration <error>%s</error> (type: %s) is invalid.',
                    $integration->id,
                    $integration->type
                ));
                $this->stdErr->writeln('');
                $this->listValidationErrors($errors, $output);

                return 4;
            }

            throw $e;
        }

        $this->stdErr->writeln("Integration <info>{$integration->id}</info> (<info>{$integration->type}</info>) updated");
        $this->ensureHooks($integration, [], $project);
        $this->stdErr->writeln('');

        $this->displayIntegration($integration);

        if ($this->activityService->shouldWait($input)) {
            $this->activityService->waitMultiple($result->getActivities(), $project);
        }

        return 0;
    }

    /**
     * Compare new and old integration values.
     *
     * @param mixed $a
     * @param mixed $b
     *
     * @return bool
     *   True if the values are considered the same, false otherwise.
     */
    private function valuesAreEqual($a, $b)
    {
        if (is_array($a) && is_array($b)) {
            ksort($a);
            ksort($b);
        }

        return $a === $b;
    }
}
