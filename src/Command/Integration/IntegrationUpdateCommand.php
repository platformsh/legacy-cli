<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Integration;

use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityMonitor;
use GuzzleHttp\Exception\BadResponseException;
use Platformsh\ConsoleForm\Exception\ConditionalFieldException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'integration:update', description: 'Update an integration')]
class IntegrationUpdateCommand extends IntegrationCommandBase
{
    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Io $io, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::OPTIONAL, 'The ID of the integration to update');
        $this->getForm()->configureInputDefinition($this->getDefinition());
        $this->selector->addProjectOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->activityMonitor->addWaitOptions($this->getDefinition());
        $this->addExample(
            'Switch on the "fetch branches" option for a specific integration',
            'ZXhhbXBsZSB --fetch-branches 1',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io->warnAboutDeprecatedOptions(
            ['type'],
            'The --type option is not supported on the integration:update command. The integration type cannot be changed.',
        );

        $selection = $this->selector->getSelection($input);
        $this->selection = $selection;

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

        $newValues = $this->postProcessValues($newValues, $integration);

        // Check if anything changed.
        foreach ($integration->getProperties() as $key => $currentValue) {
            if (isset($newValues[$key])) {
                // Remove any new values that are the same as the current value.
                if ($this->valuesAreEqual($currentValue, $newValues[$key])) {
                    unset($newValues[$key]);
                }
            }
        }

        if (empty($newValues)) {
            $this->stdErr->writeln('No changed values were provided to update.');
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
                    $integration->type,
                ));
                $this->stdErr->writeln('');
                $this->listValidationErrors($errors, $output);

                return 4;
            }

            throw $e;
        }

        $this->stdErr->writeln("Integration <info>{$integration->id}</info> (<info>{$integration->type}</info>) updated");
        $this->stdErr->writeln('');

        $this->displayIntegration($integration);

        if ($this->activityMonitor->shouldWait($input)) {
            $activityMonitor = $this->activityMonitor;
            $activityMonitor->waitMultiple($result->getActivities(), $selection->getProject());
        }

        return 0;
    }

    /**
     * Compare new and old integration values.
     *
     *
     * @return bool
     *   True if the values are considered the same, false otherwise.
     */
    private function valuesAreEqual(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            ksort($a);
            ksort($b);
        }

        return $a === $b;
    }
}
