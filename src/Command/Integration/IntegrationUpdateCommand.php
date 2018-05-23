<?php
namespace Platformsh\Cli\Command\Integration;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\IntegrationService;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationUpdateCommand extends CommandBase
{
    protected static $defaultName = 'integration:update';

    private $activityMonitor;
    private $api;
    private $integrationService;
    private $selector;

    public function __construct(
        ActivityMonitor $activityMonitor,
        Api $api,
        IntegrationService $integration,
        Selector $selector
    ) {
        $this->api = $api;
        $this->activityMonitor = $activityMonitor;
        $this->integrationService = $integration;
        $this->selector = $selector;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'The ID of the integration to update')
            ->setDescription('Update an integration');

        $definition = $this->getDefinition();
        $this->integrationService->getForm()->configureInputDefinition($definition);
        $this->selector->addProjectOption($definition);
        $this->activityMonitor->addWaitOptions($definition);

        $this->addExample(
            'Switch on the "fetch branches" option for a specific integration',
            'ZXhhbXBsZSB --fetch-branches 1'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->selector->getSelection($input)->getProject();

        $id = $input->getArgument('id');
        $integration = $project->getIntegration($id);
        if (!$integration) {
            try {
                $integration = $this->api->matchPartialId($id, $project->getIntegrations(), 'Integration');
            } catch (\InvalidArgumentException $e) {
                $this->stdErr->writeln($e->getMessage());
                return 1;
            }
        }

        $values = [];
        $form = $this->integrationService->getForm();
        $currentValues = $integration->getProperties();
        foreach ($form->getFields() as $key => $field) {
            $value = $field->getValueFromInput($input);
            if ($value !== null && $currentValues[$key] !== $value) {
                $values[$key] = $value;
            }
        }
        if (!$values) {
            $this->stdErr->writeln('No changed values were provided to update.');
            $this->integrationService->ensureHooks($integration);

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
        $this->integrationService->ensureHooks($integration);

        $this->integrationService->displayIntegration($integration);

        if ($this->activityMonitor->shouldWait($input)) {
            $this->activityMonitor->waitMultiple($result->getActivities(), $project);
        }

        return 0;
    }
}
