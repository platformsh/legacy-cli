<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Integration;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Client\Model\Integration;
use Platformsh\ConsoleForm\Exception\ConditionalFieldException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationAddCommand extends IntegrationCommandBase
{
    protected static $defaultName = 'integration:add';
    protected static $defaultDescription = 'Add an integration to the project';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $definition = $this->getDefinition();
        $this->getForm()->configureInputDefinition($definition);
        $this->selector->addProjectOption($definition);
        $this->activityService->configureInput($definition);

        $this->addExample(
            'Add an integration with a GitHub repository',
            '--type github --repository myuser/example-repo --token 9218376e14c2797e0d06e8d2f918d45f --fetch-branches 0'
        );
        $this->addExample(
            'Add an integration with a GitLab repository',
            '--type gitlab --server-project mygroup/example-repo --token 22fe4d70dfbc20e4f668568a0b5422e2 --base-url https://gitlab.example.com'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);
        $project = $selection->getProject();

        try {
            $values = $this->getForm()->resolveOptions($input, $this->stdErr, $this->questionHelper);
        } catch (ConditionalFieldException $e) {
            return $this->handleConditionalFieldException($e);
        }

        // Validate credentials for new Bitbucket integrations.
        if (isset($values['type']) && $values['type'] === 'bitbucket' && isset($values['app_credentials'])) {
            $result = $this->validateBitbucketCredentials($values['app_credentials']);
            if ($result !== true) {
                $this->stdErr->writeln($result);

                return 1;
            }
        }

        // Omit all empty, non-required fields when creating a new integration.
        foreach ($this->getForm()->getFields() as $name => $field) {
            if (isset($values[$name]) && !$field->isRequired() && $field->isEmpty($values[$name])) {
                unset($values[$name]);
            }
        }

        $values = $this->postProcessValues($values);

        // Confirm this action for Git source integrations.
        if (isset($values['type']) && in_array($values['type'], ['github', 'gitlab', 'bitbucket', 'bitbucket_server'])) {
            $this->stdErr->writeln(
                "<comment>Warning:</comment> adding a '" . $values['type'] . "' integration will automatically synchronize code from the external Git repository."
                . "\nThis means it can overwrite all the code in your project.\n"
            );
            if (!$this->questionHelper->confirm('Are you sure you want to continue?', false)) {
                return 1;
            }
        }

        // Save the current Git remote (to see if we need to update it, for Git source integrations).
        $oldGitUrl = $project->getGitUrl();

        try {
            $result = $project->addIntegration($values['type'], $values);
        } catch (BadResponseException $e) {
            if ($errors = Integration::listValidationErrors($e)) {
                $this->stdErr->writeln('<error>The integration is invalid.</error>');
                $this->stdErr->writeln('');
                $this->listValidationErrors($errors, $output);

                return 4;
            }

            throw $e;
        }

        /** @var \Platformsh\Client\Model\Integration $integration */
        /** @noinspection PhpUnhandledExceptionInspection */
        $integration = $result->getEntity();

        $this->stdErr->writeln("Created integration <info>$integration->id</info> (type: {$values['type']})");

        $success = true;
        if ($this->activityService->shouldWait($input)) {
            $success = $this->activityService->waitMultiple($result->getActivities(), $project);
        }

        $this->updateGitUrl($oldGitUrl, $project);

        $this->displayIntegration($integration);

        return $success ? 0 : 1;
    }
}
