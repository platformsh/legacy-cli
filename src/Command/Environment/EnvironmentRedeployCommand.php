<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'environment:redeploy', description: 'Redeploy an environment', aliases: ['redeploy'])]
class EnvironmentRedeployCommand extends CommandBase
{

    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Api $api, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->activityMonitor->addWaitOptions($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(chooseEnvFilter: SelectorConfig::filterEnvsMaybeActive()));

        $environment = $selection->getEnvironment();

        if (!$environment->operationAvailable('redeploy', true)) {
            $this->stdErr->writeln(
                "Operation not available: The environment " . $this->api->getEnvironmentLabel($environment, 'error') . " can't be redeployed."
            );

            if (!$environment->isActive()) {
                $this->stdErr->writeln('The environment is not active.');
            }

            return 1;
        }

        $questionHelper = $this->questionHelper;
        if (!$questionHelper->confirm('Are you sure you want to redeploy the environment <comment>' . $environment->id . '</comment>?')) {
            return 1;
        }

        $result = $environment->runOperation('redeploy');

        if ($this->activityMonitor->shouldWait($input)) {
            $activityMonitor = $this->activityMonitor;
            $success = $activityMonitor->waitMultiple($result->getActivities(), $selection->getProject());
            if (!$success) {
                return 1;
            }
        }

        return 0;
    }
}
