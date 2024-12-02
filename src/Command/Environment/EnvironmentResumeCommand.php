<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'environment:resume', description: 'Resume a paused environment')]
class EnvironmentResumeCommand extends CommandBase
{

    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Api $api, private readonly QuestionHelper $questionHelper)
    {
        parent::__construct();
    }
    protected function configure()
    {
        $this->addProjectOption()
            ->addEnvironmentOption();
        $this->addWaitOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->chooseEnvFilter = $this->filterEnvsByStatus(['paused']);
        $this->validateInput($input);

        $environment = $this->getSelectedEnvironment();

        if (!$environment->operationAvailable('resume', true)) {
            if ($environment->status !== 'paused') {
                $this->stdErr->writeln(sprintf('The environment %s is not paused. Only paused environments can be resumed.', $this->api->getEnvironmentLabel($environment, 'comment')));
            } else {
                $this->stdErr->writeln(sprintf("Operation not available: The environment %s can't be resumed.", $this->api->getEnvironmentLabel($environment, 'error')));
            }

            return 1;
        }

        /** @var QuestionHelper $questionHelper */
        $questionHelper = $this->questionHelper;
        if (!$questionHelper->confirm('Are you sure you want to resume the paused environment <comment>' . $environment->id . '</comment>?')) {
            return 1;
        }

        $result = $environment->runOperation('resume');
        $this->api->clearEnvironmentsCache($environment->project);

        if ($this->shouldWait($input)) {
            /** @var ActivityMonitor $activityMonitor */
            $activityMonitor = $this->activityMonitor;
            $success = $activityMonitor->waitMultiple($result->getActivities(), $this->getSelectedProject());
            if (!$success) {
                return 1;
            }
        }

        return 0;
    }
}
