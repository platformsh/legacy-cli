<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentResumeCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('environment:resume')
            ->setDescription('Resume a paused environment');
        $this->addProjectOption()
            ->addEnvironmentOption();
        $this->addWaitOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $environment = $this->getSelectedEnvironment();

        if (!$environment->operationAvailable('resume', true)) {
            if ($environment->status !== 'paused') {
                $this->stdErr->writeln(sprintf('The environment %s is not paused. Only paused environments can be resumed.', $this->api()->getEnvironmentLabel($environment, 'comment')));
            } else {
                $this->stdErr->writeln(sprintf("Operation not available: The environment %s can't be resumed.", $this->api()->getEnvironmentLabel($environment, 'error')));
            }

            return 1;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if (!$questionHelper->confirm('Are you sure you want to resume the paused environment <comment>' . $environment->id . '</comment>?')) {
            return 1;
        }
        $this->stdErr->writeln('');

        $result = $environment->runOperation('resume');
        $this->api()->clearEnvironmentsCache($environment->project);

        if ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $success = $activityMonitor->waitMultiple($result->getActivities(), $this->getSelectedProject());
            if (!$success) {
                return 1;
            }
        }

        return 0;
    }
}
