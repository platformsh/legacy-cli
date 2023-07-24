<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentPauseCommand extends CommandBase
{

    const PAUSE_HELP = <<<EOF
Pausing an environment helps to reduce resource consumption and carbon emissions.

The environment will be unavailable until it is resumed. No data will be lost.
EOF;

    protected function configure()
    {
        $this
            ->setName('environment:pause')
            ->setDescription('Pause an environment');
        $this->addProjectOption()
            ->addEnvironmentOption();
        $this->addWaitOptions();
        $this->setHelp(self::PAUSE_HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $environment = $this->getSelectedEnvironment();

        if ($environment->status === 'paused') {
            $this->stdErr->writeln(sprintf(
                'The environment %s is already paused.',
                $this->api()->getEnvironmentLabel($environment)
            ));
            return 0;
        }

        if (!$environment->operationAvailable('pause', true)) {
            $this->stdErr->writeln(sprintf(
                "Operation not available: The environment %s can't be paused.",
                $this->api()->getEnvironmentLabel($environment, 'error')
            ));

            if (!$environment->isActive()) {
                $this->stdErr->writeln('The environment is not active.');
            }

            return 1;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $text = self::PAUSE_HELP . "\n\n" . sprintf('Are you sure you want to pause the environment <comment>%s</comment>?', $environment->id);
        if (!$questionHelper->confirm($text)) {
            return 1;
        }

        $result = $environment->runOperation('pause');
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
