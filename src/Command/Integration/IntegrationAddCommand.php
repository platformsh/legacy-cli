<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Integration;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\IntegrationService;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationAddCommand extends CommandBase
{
    protected static $defaultName = 'integration:add';

    private $activityService;
    private $integrationService;
    private $questionHelper;
    private $selector;

    public function __construct(
        ActivityService $activityService,
        IntegrationService $integration,
        QuestionHelper $questionHelper,
        Selector $selector
    ) {
        $this->activityService = $activityService;
        $this->integrationService = $integration;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setDescription('Add an integration to the project');

        $definition = $this->getDefinition();
        $this->integrationService->getForm()->configureInputDefinition($definition);
        $this->selector->addProjectOption($definition);
        $this->activityService->configureInput($definition);

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
        $selection = $this->selector->getSelection($input);

        $values = $this->integrationService->getForm()
                       ->resolveOptions($input, $this->stdErr, $this->questionHelper);

        // Omit all empty, non-required fields when creating a new integration.
        foreach ($this->integrationService->getForm()->getFields() as $name => $field) {
            if (isset($values[$name]) && !$field->isRequired() && $field->isEmpty($values[$name])) {
                unset($values[$name]);
            }
        }

        $result = $selection->getProject()
                       ->addIntegration($values['type'], $values);

        /** @var \Platformsh\Client\Model\Integration $integration */
        /** @noinspection PhpUnhandledExceptionInspection */
        $integration = $result->getEntity();

        $this->integrationService->ensureHooks($integration);

        $this->stdErr->writeln("Created integration <info>$integration->id</info> (type: {$values['type']})");

        $success = true;
        if ($this->activityService->shouldWait($input)) {
            $success = $this->activityService->waitMultiple($result->getActivities(), $selection->getProject());
        }

        $this->integrationService->displayIntegration($integration);

        return $success ? 0 : 1;
    }
}
