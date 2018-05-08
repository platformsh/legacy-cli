<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentRedeployCommand extends CommandBase
{

    protected static $defaultName = 'environment:redeploy';

    protected function configure()
    {
        $this->setAliases(['redeploy'])
            ->setDescription('Redeploy an environment');
        $this->addProjectOption()
            ->addEnvironmentOption();
        $this->addWaitOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $environment = $this->getSelectedEnvironment();

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if (!$questionHelper->confirm('Are you sure you want to redeploy the environment <comment>' . $environment->id . '</comment>?')) {
            return 1;
        }

        $activity = $environment->redeploy();

        if ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $success = $activityMonitor->waitAndLog($activity);
            if (!$success) {
                return 1;
            }
        }

        return 0;
    }
}
