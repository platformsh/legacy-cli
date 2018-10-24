<?php
namespace Platformsh\Cli\Command\Integration;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Util\NestedArrayUtil;
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
            'The option --%s is deprecated and will be removed in a future version.'
        );

        $this->validateInput($input);

        $id = $input->getArgument('id');
        $project = $this->getSelectedProject();
        $integration = $project->getIntegration($id);
        if (!$integration) {
            try {
                $integration = $this->api()->matchPartialId($id, $project->getIntegrations(), 'Integration');
            } catch (\InvalidArgumentException $e) {
                $this->stdErr->writeln($e->getMessage());
                return 1;
            }
        }

        // Get the values supplied via the command-line options.
        $newValues = [];
        foreach ($this->getForm()->getFields() as $key => $field) {
            $value = $field->getValueFromInput($input);
            $parents = $field->getValueKeys() ?: [$key];
            if ($value !== null) {
                NestedArrayUtil::setNestedArrayValue($newValues, $parents, $value, true);
            }
        }

        // Merge current values with new values, accounting for nested arrays.
        foreach ($integration->getProperties() as $key => $currentValue) {
            if (isset($newValues[$key])) {
                // If the new value is an array, it needs to be merged with the
                // old values, e.g. ['foo' => 1, 'bar' => 7] plus ['foo' => 2]
                // will become ['foo' => 2, 'bar' => 7].
                if (is_array($currentValue)) {
                    $newValues[$key] = array_replace_recursive($currentValue, $newValues[$key]);
                }

                // Remove any new values that are the same as the current value.
                if ($this->valuesAreEqual($currentValue, $newValues[$key])) {
                    unset($newValues[$key]);
                }
            }
        }

        if (!$newValues) {
            $this->stdErr->writeln('No changed values were provided to update.');
            $this->stdErr->writeln('');
            $this->ensureHooks($integration);

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
