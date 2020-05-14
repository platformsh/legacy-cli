<?php
namespace Platformsh\Cli\Command\Integration;

use GuzzleHttp\Exception\BadResponseException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationUpdateCommand extends IntegrationCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('integration:update')
            ->addArgument('id', InputArgument::REQUIRED, 'The ID of the integration to update')
            ->setDescription('Update an integration');
        $this->getForm()->configureInputDefinition($this->getDefinition());
        $this->addProjectOption()->addWaitOptions();
        $this->addExample(
            'Switch on the "fetch branches" option for a specific integration',
            'ZXhhbXBsZSB --fetch-branches 1'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->warnAboutDeprecatedOptions(
            ['type'],
            'The --type option is not supported on the integration:update command. The integration type cannot be changed.'
        );

        $this->validateInput($input);

        $project = $this->getSelectedProject();

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
            $this->ensureHooks($integration);
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
        $this->ensureHooks($integration);
        $this->stdErr->writeln('');

        $this->displayIntegration($integration);

        if ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $activityMonitor->waitMultiple($result->getActivities(), $this->getSelectedProject());
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
