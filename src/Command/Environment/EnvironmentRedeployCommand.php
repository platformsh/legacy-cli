<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Exception\EnvironmentStateException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentRedeployCommand extends CommandBase
{
    protected $hiddenInList = true;

    protected function configure()
    {
        $this
            ->setName('environment:redeploy')
            ->setAliases(['redeploy'])
            ->setDescription('Redeploy an environment');
        $this->addProjectOption()
            ->addEnvironmentOption();
        $this->addNoWaitOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $environment = $this->getSelectedEnvironment();

        if (!$environment->operationAvailable('manage-variables')) {
            throw new EnvironmentStateException(
                sprintf(
                    "Operation not available: cannot set variables on environment <error>%s</error>.",
                    $environment->id
                ), $environment
            );
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if (!$questionHelper->confirm('Are you sure you want to redeploy the environment <comment>' . $environment->id . '</comment>?')) {
            return 1;
        }

        $id = '_cli_redeploy';
        $currentValue = $environment->getVariable($id);

        $newValue = date('c');
        if ($newValue === $currentValue) {
            $newValue .= '_1';
        }

        $this->stdErr->writeln(sprintf(
            'Setting variable <comment>%s</comment> to <comment>%s</comment> to trigger redeployment.',
            $id,
            $newValue
        ));
        $result = $environment->setVariable($id, $newValue);

        if (!$input->getOption('no-wait')) {
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
