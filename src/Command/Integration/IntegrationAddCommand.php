<?php
namespace Platformsh\Cli\Command\Integration;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Client\Model\Integration;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationAddCommand extends IntegrationCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('integration:add')
            ->setDescription('Add an integration to the project');
        $this->getForm()->configureInputDefinition($this->getDefinition());
        $this->addProjectOption()->addWaitOptions();
        $this->addExample(
            'Add an integration with a GitHub repository',
            '--type github --repository myuser/example-repo --token 9218376e14c2797e0d06e8d2f918d45f --fetch-branches 0'
        );
        $this->addExample(
            'Add an integration with a GitLab repository',
            '--type gitlab --repository mygroup/example-repo --token 22fe4d70dfbc20e4f668568a0b5422e2 --base-url https://gitlab.example.com'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $values = $this->getForm()
                       ->resolveOptions($input, $this->stdErr, $questionHelper);

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


        try {
            $result = $this->getSelectedProject()
                ->addIntegration($values['type'], $values);
        } catch (BadResponseException $e) {
            if ($errors = Integration::listValidationErrors($e)) {
                $this->stdErr->writeln('<error>The integration is invalid.</error>');
                $this->stdErr->writeln('');
                $this->listValidationErrors($errors, $output);

                return 4;
            }

            throw $e;
        }

        /** @var Integration $integration */
        $integration = $result->getEntity();

        $this->ensureHooks($integration);

        $this->stdErr->writeln("Created integration <info>$integration->id</info> (type: {$values['type']})");

        $success = true;
        if ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $success = $activityMonitor->waitMultiple($result->getActivities(), $this->getSelectedProject());
        }

        $this->displayIntegration($integration);

        return $success ? 0 : 1;
    }
}
