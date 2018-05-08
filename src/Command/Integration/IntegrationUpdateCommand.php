<?php
namespace Platformsh\Cli\Command\Integration;

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
        $this->getForm()->configureInputDefinition($this->getDefinition());
        $this->addProjectOption()->addWaitOptions();
        $this->addExample(
            'Switch on the "fetch branches" option for a specific integration',
            'ZXhhbXBsZSB --fetch-branches 1'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
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

        $values = [];
        $form = $this->getForm();
        $currentValues = $integration->getProperties();
        foreach ($form->getFields() as $key => $field) {
            $value = $field->getValueFromInput($input);
            if ($value !== null && $currentValues[$key] !== $value) {
                $values[$key] = $value;
            }
        }
        if (!$values) {
            $this->stdErr->writeln('No changed values were provided to update.');
            $this->ensureHooks($integration);

            return 1;
        }

        // Complete the PATCH request with the current values. This is a
        // workaround: at the moment a PATCH with only the changed values will
        // cause a 500 error.
        foreach ($currentValues as $key => $currentValue) {
            if ($key !== 'id' && !array_key_exists($key, $values)) {
                $values[$key] = $currentValue;
            }
        }

        $result = $integration->update($values);
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
}
